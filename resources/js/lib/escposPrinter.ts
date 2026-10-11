/**
 * Direct printing to an ESC/POS receipt printer from the browser: over WebUSB for USB printers or
 * Web Serial for serial, USB-serial and Bluetooth-serial ones (Chrome/Edge), or through QZ Tray
 * (any browser) to a printer installed in the operating system, by its name. No print dialog;
 * the server's ESC/POS bytes go straight to the printer.
 *
 * Each "slot" (the bill printer, the kitchen printer) remembers its device in this browser, so it
 * is picked once. On Windows a USB printer can only be used over WebUSB once its driver is
 * swapped for WinUSB (e.g. with Zadig); otherwise connect it as a serial port or print with the
 * dialog.
 */

import admin from '@/routes/admin';

export type PrinterSlot = 'bill' | 'kot';
export type PrinterKind = 'usb' | 'serial' | 'qz';
type Saved = {
    kind: PrinterKind;
    vendorId?: number;
    productId?: number;
    printerName?: string;
    label: string;
};

// Just the parts of WebUSB and Web Serial used here (they aren't in TypeScript's DOM types).
type UsbEndpoint = {
    direction: 'in' | 'out';
    type: string;
    endpointNumber: number;
};
type UsbAlternate = { endpoints: UsbEndpoint[] };
type UsbInterface = { interfaceNumber: number; alternate: UsbAlternate };
type UsbDevice = {
    vendorId: number;
    productId: number;
    productName?: string;
    opened: boolean;
    configuration: { interfaces: UsbInterface[] } | null;
    open(): Promise<void>;
    close(): Promise<void>;
    selectConfiguration(value: number): Promise<void>;
    claimInterface(number: number): Promise<void>;
    releaseInterface(number: number): Promise<void>;
    transferOut(endpoint: number, data: BufferSource): Promise<unknown>;
};
type Usb = {
    requestDevice(options: { filters: object[] }): Promise<UsbDevice>;
    getDevices(): Promise<UsbDevice[]>;
};
type SerialPort = {
    getInfo(): { usbVendorId?: number; usbProductId?: number };
    open(options: { baudRate: number }): Promise<void>;
    close(): Promise<void>;
    writable: WritableStream<Uint8Array> | null;
};
type Serial = {
    requestPort(): Promise<SerialPort>;
    getPorts(): Promise<SerialPort[]>;
};

const storageKey = (slot: PrinterSlot) => `pos.printer.${slot}`;
const usb = (): Usb | undefined => (navigator as unknown as { usb?: Usb }).usb;
const serial = (): Serial | undefined =>
    (navigator as unknown as { serial?: Serial }).serial;

/** WebUSB and Web Serial need Chrome/Edge; QZ Tray works in any browser once it is installed. */
export function supportedKinds(): PrinterKind[] {
    return [
        ...(usb() ? (['usb'] as const) : []),
        ...(serial() ? (['serial'] as const) : []),
        'qz',
    ];
}

type Qz = typeof import('qz-tray').default;
let qzLoaded: Promise<Qz> | null = null;

function xsrfToken(): string {
    return decodeURIComponent(
        document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]*)/)?.[1] ?? '',
    );
}

/** The qz-tray library, loaded on first use and set to sign with this site's certificate. */
function loadQz(): Promise<Qz> {
    qzLoaded ??= import('qz-tray').then(({ default: qz }) => {
        qz.security.setCertificatePromise(async () =>
            (
                await fetch(admin.pos.qz.certificate().url, {
                    credentials: 'same-origin',
                })
            ).text(),
        );
        qz.security.setSignatureAlgorithm('SHA512');
        qz.security.setSignaturePromise(async (toSign) => {
            const response = await fetch(admin.pos.qz.sign().url, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'text/plain',
                    'X-XSRF-TOKEN': xsrfToken(),
                },
                body: JSON.stringify({ request: toSign }),
            });

            return response.ok ? response.text() : '';
        });

        return qz;
    });

    return qzLoaded;
}

/**
 * QZ Tray, connected (again, if it was restarted). With no certificate set up on the server,
 * QZ Tray asks the user to allow the site instead.
 */
async function qzTray(): Promise<Qz> {
    const qz = await loadQz();

    if (!qz.websocket.isActive()) {
        try {
            await qz.websocket.connect({ retries: 1, delay: 1 });
        } catch (error) {
            throw new Error(
                `QZ Tray isn't running on this computer. Install it from qz.io and start it. (${error instanceof Error ? error.message : String(error)})`,
            );
        }
    }

    return qz;
}

/** The printers installed on this computer, as QZ Tray sees them. */
export async function qzPrinters(): Promise<string[]> {
    const found = await (await qzTray()).printers.find();

    return Array.isArray(found) ? found : [found];
}

/** Remembers an installed printer (picked from qzPrinters) for the slot. */
export function saveQzPrinter(slot: PrinterSlot, printerName: string): Saved {
    const saved: Saved = { kind: 'qz', printerName, label: printerName };

    try {
        localStorage.setItem(storageKey(slot), JSON.stringify(saved));
    } catch {
        // Storage blocked: the printer works for this page only.
    }

    return saved;
}

async function printQz(saved: Saved, bytes: Uint8Array): Promise<void> {
    const qz = await qzTray();
    let binary = '';

    for (let offset = 0; offset < bytes.length; offset += 0x8000) {
        binary += String.fromCharCode(
            ...bytes.subarray(offset, offset + 0x8000),
        );
    }

    await qz.print(qz.configs.create(saved.printerName ?? ''), [
        {
            type: 'raw',
            format: 'command',
            flavor: 'base64',
            data: btoa(binary),
        },
    ]);
}

/** The printer this browser remembers for the slot, if any. */
export function savedPrinter(slot: PrinterSlot): Saved | null {
    try {
        const raw = localStorage.getItem(storageKey(slot));

        return raw ? (JSON.parse(raw) as Saved) : null;
    } catch {
        return null;
    }
}

export function forgetPrinter(slot: PrinterSlot): void {
    try {
        localStorage.removeItem(storageKey(slot));
    } catch {
        // Storage blocked: nothing was remembered.
    }
}

/** Asks the user to pick a USB or serial printer (needs a click). QZ Tray printers use saveQzPrinter. */
export async function connectPrinter(
    slot: PrinterSlot,
    kind: Exclude<PrinterKind, 'qz'>,
): Promise<Saved> {
    let saved: Saved;

    if (kind === 'usb') {
        const device = await usb()!.requestDevice({ filters: [] });
        saved = {
            kind,
            vendorId: device.vendorId,
            productId: device.productId,
            label: device.productName || 'USB printer',
        };
    } else {
        const port = await serial()!.requestPort();
        const info = port.getInfo();
        saved = {
            kind,
            vendorId: info.usbVendorId,
            productId: info.usbProductId,
            label: 'Serial printer',
        };
    }

    try {
        localStorage.setItem(storageKey(slot), JSON.stringify(saved));
    } catch {
        // Storage blocked: the printer works for this page only.
    }

    return saved;
}

/** Fetches ESC/POS bytes from the app and prints them on the slot's printer. */
export async function printFrom(slot: PrinterSlot, url: string): Promise<void> {
    const response = await fetch(url, {
        credentials: 'same-origin',
        headers: { Accept: 'application/octet-stream' },
    });

    if (!response.ok) {
        throw new Error(`The print couldn't be made (${response.status}).`);
    }

    await printBytes(slot, new Uint8Array(await response.arrayBuffer()));
}

export async function printBytes(
    slot: PrinterSlot,
    bytes: Uint8Array,
): Promise<void> {
    const saved = savedPrinter(slot);

    if (!saved) {
        throw new Error('No printer is connected in this browser.');
    }

    if (saved.kind === 'usb') {
        await printUsb(saved, bytes);
    } else if (saved.kind === 'qz') {
        await printQz(saved, bytes);
    } else {
        await printSerial(saved, bytes);
    }
}

async function printUsb(saved: Saved, bytes: Uint8Array): Promise<void> {
    const device = (await usb()?.getDevices())?.find(
        (entry) =>
            entry.vendorId === saved.vendorId &&
            entry.productId === saved.productId,
    );

    if (!device) {
        throw new Error(
            `${saved.label} isn't plugged in, or this browser lost access to it. Connect it again.`,
        );
    }

    if (!device.opened) {
        await device.open();
    }

    if (device.configuration === null) {
        await device.selectConfiguration(1);
    }

    const target = device
        .configuration!.interfaces.map((entry) => ({
            entry,
            endpoint: entry.alternate.endpoints.find(
                (endpoint) =>
                    endpoint.direction === 'out' && endpoint.type === 'bulk',
            ),
        }))
        .find((candidate) => candidate.endpoint);

    if (!target?.endpoint) {
        throw new Error(`${saved.label} has no printer output.`);
    }

    await device.claimInterface(target.entry.interfaceNumber);

    try {
        for (let offset = 0; offset < bytes.length; offset += 4096) {
            await device.transferOut(
                target.endpoint.endpointNumber,
                bytes.slice(offset, offset + 4096),
            );
        }
    } finally {
        await device.releaseInterface(target.entry.interfaceNumber);
        await device.close();
    }
}

async function printSerial(saved: Saved, bytes: Uint8Array): Promise<void> {
    const ports = (await serial()?.getPorts()) ?? [];
    const port =
        ports.find(
            (entry) =>
                entry.getInfo().usbVendorId === saved.vendorId &&
                entry.getInfo().usbProductId === saved.productId,
        ) ?? (ports.length === 1 ? ports[0] : undefined);

    if (!port) {
        throw new Error(`${saved.label} isn't available. Connect it again.`);
    }

    await port.open({ baudRate: 9600 });

    try {
        const writer = port.writable!.getWriter();
        await writer.write(bytes);
        writer.releaseLock();
    } finally {
        await port.close();
    }
}
