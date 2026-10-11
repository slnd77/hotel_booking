/** The parts of qz-tray (which ships no types) the POS printing uses. */
declare module 'qz-tray' {
    type QzPrintData = {
        type: 'raw';
        format: 'command';
        flavor: 'base64' | 'plain' | 'hex';
        data: string;
    };

    const qz: {
        security: {
            setCertificatePromise(handler: () => Promise<string>): void;
            setSignatureAlgorithm(
                algorithm: 'SHA1' | 'SHA256' | 'SHA512',
            ): void;
            setSignaturePromise(
                factory: (toSign: string) => Promise<string>,
            ): void;
        };
        websocket: {
            isActive(): boolean;
            connect(options?: {
                retries?: number;
                delay?: number;
            }): Promise<void>;
        };
        printers: {
            find(query?: string): Promise<string | string[]>;
            getDefault(): Promise<string>;
        };
        configs: {
            create(printer: string, options?: Record<string, unknown>): unknown;
        };
        print(config: unknown, data: QzPrintData[]): Promise<void>;
    };

    export default qz;
}
