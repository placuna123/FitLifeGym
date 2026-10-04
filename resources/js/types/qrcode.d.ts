// Minimal types for the parts of the `qrcode` package FitGym uses (it ships no typings).
declare module 'qrcode' {
    interface QRCodeToDataURLOptions {
        margin?: number;
        width?: number;
        errorCorrectionLevel?: 'L' | 'M' | 'Q' | 'H';
        color?: { dark?: string; light?: string };
    }

    export function toDataURL(text: string, options?: QRCodeToDataURLOptions): Promise<string>;

    const QRCode: { toDataURL: typeof toDataURL };
    export default QRCode;
}
