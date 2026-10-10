/**
 * Passkeys in the browser: turns the server's WebAuthn options (base64url JSON) into what
 * navigator.credentials expects, and the resulting credential back into JSON for the server.
 */

type JsonOptions = Record<string, unknown>;

function toBuffer(value: string): ArrayBuffer {
    const base64 = value
        .replace(/-/g, '+')
        .replace(/_/g, '/')
        .padEnd(Math.ceil(value.length / 4) * 4, '=');
    const binary = atob(base64);
    const bytes = new Uint8Array(binary.length);

    for (let index = 0; index < binary.length; index++) {
        bytes[index] = binary.charCodeAt(index);
    }

    return bytes.buffer;
}

function toBase64Url(
    buffer: ArrayBuffer | null | undefined,
): string | undefined {
    if (!buffer) {
        return undefined;
    }

    let binary = '';
    new Uint8Array(buffer).forEach(
        (byte) => (binary += String.fromCharCode(byte)),
    );

    return btoa(binary)
        .replace(/\+/g, '-')
        .replace(/\//g, '_')
        .replace(/=+$/, '');
}

function withBufferIds(
    descriptors: unknown,
): PublicKeyCredentialDescriptor[] | undefined {
    return Array.isArray(descriptors)
        ? descriptors.map(
              (descriptor: {
                  id: string;
                  type: PublicKeyCredentialType;
                  transports?: AuthenticatorTransport[];
              }) => ({
                  ...descriptor,
                  id: toBuffer(descriptor.id),
              }),
          )
        : undefined;
}

export function passkeysSupported(): boolean {
    return (
        typeof window !== 'undefined' &&
        'PublicKeyCredential' in window &&
        !!navigator.credentials
    );
}

function credentialJson(
    credential: PublicKeyCredential,
): Record<string, unknown> {
    const response = credential.response as AuthenticatorAttestationResponse &
        AuthenticatorAssertionResponse;

    return {
        id: credential.id,
        rawId: toBase64Url(credential.rawId),
        type: credential.type,
        authenticatorAttachment:
            credential.authenticatorAttachment ?? undefined,
        clientExtensionResults: credential.getClientExtensionResults(),
        response: {
            clientDataJSON: toBase64Url(response.clientDataJSON),
            attestationObject: toBase64Url(response.attestationObject),
            transports:
                typeof response.getTransports === 'function'
                    ? response.getTransports()
                    : undefined,
            authenticatorData: toBase64Url(response.authenticatorData),
            signature: toBase64Url(response.signature),
            userHandle: toBase64Url(response.userHandle),
        },
    };
}

/** Registration: asks the device to create a passkey for the user. */
export async function createPasskey(
    options: JsonOptions,
): Promise<Record<string, unknown>> {
    const user = options.user as {
        id: string;
        name: string;
        displayName: string;
    };
    const credential = (await navigator.credentials.create({
        publicKey: {
            ...(options as unknown as PublicKeyCredentialCreationOptions),
            challenge: toBuffer(options.challenge as string),
            user: { ...user, id: toBuffer(user.id) },
            excludeCredentials: withBufferIds(options.excludeCredentials),
        },
    })) as PublicKeyCredential | null;

    if (!credential) {
        throw new Error('No passkey was created.');
    }

    return credentialJson(credential);
}

/** Verification: asks the device to sign in with one of the user's passkeys. */
export async function getPasskey(
    options: JsonOptions,
): Promise<Record<string, unknown>> {
    const credential = (await navigator.credentials.get({
        publicKey: {
            ...(options as unknown as PublicKeyCredentialRequestOptions),
            challenge: toBuffer(options.challenge as string),
            allowCredentials: withBufferIds(options.allowCredentials),
        },
    })) as PublicKeyCredential | null;

    if (!credential) {
        throw new Error('No passkey was used.');
    }

    return credentialJson(credential);
}

/** A readable message for a failed WebAuthn ceremony (cancelled, timed out, unsupported…). */
export function passkeyErrorMessage(error: unknown): string {
    if (
        error instanceof DOMException &&
        (error.name === 'NotAllowedError' || error.name === 'AbortError')
    ) {
        return 'The passkey request was cancelled or timed out.';
    }

    if (error instanceof DOMException && error.name === 'InvalidStateError') {
        return 'This device already has a passkey for your account.';
    }

    return error instanceof Error
        ? error.message
        : 'The passkey request failed.';
}

function xsrfToken(): string {
    const match = document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]*)/);

    return match ? decodeURIComponent(match[1]) : '';
}

/** JSON request with the session's CSRF token; throws the server's first message on failure. */
export async function requestJson<T>(
    url: string,
    method: 'GET' | 'POST' | 'DELETE' = 'GET',
    body?: Record<string, unknown>,
): Promise<T> {
    const response = await fetch(url, {
        method,
        credentials: 'same-origin',
        headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            'X-XSRF-TOKEN': xsrfToken(),
        },
        // fetch() refuses a body on GET.
        ...(method !== 'GET' && body ? { body: JSON.stringify(body) } : {}),
    });
    const data = (await response.json().catch(() => ({}))) as {
        message?: string;
        errors?: Record<string, string[]>;
    } & T;

    if (!response.ok) {
        throw new Error(
            Object.values(data.errors ?? {})[0]?.[0] ??
                data.message ??
                `Request failed (${response.status}).`,
        );
    }

    return data;
}
