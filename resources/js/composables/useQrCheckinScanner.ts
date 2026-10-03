import { Html5Qrcode, Html5QrcodeSupportedFormats } from 'html5-qrcode';
import { onUnmounted, ref, type Ref } from 'vue';

type QrDetection = {
    rawValue: string;
};

type UseQrCheckinScannerOptions = {
    onDetected: (result: QrDetection) => Promise<void> | void;
};

type NavigatorWithMedia = Navigator & {
    mediaDevices?: {
        getUserMedia?: (constraints: MediaStreamConstraints) => Promise<MediaStream>;
    };
};

function buildPreferredCameraConstraints(): MediaTrackConstraints {
    return {
        facingMode: { ideal: 'environment' },
        width: { ideal: 1280 },
        height: { ideal: 720 },
    };
}

export function useQrCheckinScanner(options: UseQrCheckinScannerOptions) {
    const videoRef: Ref<HTMLDivElement | null> = ref(null);
    const scannerActive = ref(false);
    const scannerBusy = ref(false);
    const scannerSupported = ref(true);
    const scannerError = ref('');
    const scannerStatus = ref('');
    const manualQrPayload = ref('');

    let stream: MediaStream | null = null;
    let lastRawValue = '';
    let lastRawValueAt = 0;
    let html5QrCode: Html5Qrcode | null = null;
    let scannerElementId = `fitgym-qr-scanner-${Math.random().toString(36).slice(2)}`;

    function normalizeRawQrValue(rawValue: string): string | null {
        const trimmed = rawValue.trim();
        return trimmed || null;
    }

    async function stopScanner() {
        if (html5QrCode) {
            try {
                if (html5QrCode.isScanning) {
                    await html5QrCode.stop();
                }
            } catch {
                // ignore stop errors
            }
            try {
                await html5QrCode.clear();
            } catch {
                // ignore clear errors
            }
            html5QrCode = null;
        }

        scannerActive.value = false;
    }

    async function handleDetection(rawValue: string) {
        const now = Date.now();
        if (rawValue === lastRawValue && now - lastRawValueAt < 2000) return;

        lastRawValue = rawValue;
        lastRawValueAt = now;

        const normalizedQrValue = normalizeRawQrValue(rawValue);
        if (!normalizedQrValue) {
            scannerStatus.value = 'QR found, but the payload is empty.';
            return;
        }

        scannerBusy.value = true;
        scannerError.value = '';
        scannerStatus.value = 'QR detected. Recording attendance...';

        try {
            await stopScanner();
            await options.onDetected({ rawValue: normalizedQrValue });
            manualQrPayload.value = '';
            scannerStatus.value = 'Attendance recorded successfully.';
        } catch (error) {
            const err = error as { message?: string };
            scannerError.value = err.message || 'Failed to process scanned QR code.';
        } finally {
            scannerBusy.value = false;
        }
    }

    async function startScanner() {
        scannerError.value = '';
        scannerStatus.value = 'Opening camera...';
        scannerSupported.value = true;

        const nav = navigator as NavigatorWithMedia;
        if (!nav.mediaDevices?.getUserMedia) {
            scannerSupported.value = false;
            scannerStatus.value = '';
            scannerError.value = 'This browser does not support camera access. Use the manual QR payload field instead.';
            return;
        }

        if (scannerActive.value) return;

        try {
            const host = videoRef.value;
            if (!host) {
                throw new Error('Scanner container is not ready.');
            }

            host.innerHTML = '';
            host.id = scannerElementId;
            html5QrCode = new Html5Qrcode(scannerElementId, {
                formatsToSupport: [Html5QrcodeSupportedFormats.QR_CODE],
                useBarCodeDetectorIfSupported: true,
                verbose: false,
            });

            await html5QrCode.start(
                buildPreferredCameraConstraints(),
                {
                    fps: 12,
                    videoConstraints: buildPreferredCameraConstraints(),
                },
                async (decodedText) => {
                    await handleDetection(decodedText);
                },
                () => {
                    // Ignore per-frame decode misses.
                }
            );

            scannerActive.value = true;
            scannerStatus.value = 'Camera ready. Center the full member QR code in the frame.';
        } catch (error) {
            await stopScanner();
            const err = error as { message?: string; name?: string };
            if (err?.name === 'NotAllowedError') {
                scannerError.value = 'Camera permission was blocked. Allow camera access to scan the QR code.';
            } else if (String(err?.message || '').toLowerCase().includes('camera')) {
                scannerError.value = err.message || 'Unable to start the camera scanner.';
            } else {
                scannerError.value = err.message || 'Unable to start the QR scanner.';
            }
            scannerStatus.value = '';
        }
    }

    async function submitManualPayload() {
        scannerError.value = '';
        const payload = manualQrPayload.value.trim();
        if (!payload) {
            scannerError.value = 'Paste the QR payload first.';
            return;
        }

        await handleDetection(payload);
    }

    onUnmounted(() => {
        void stopScanner();
    });

    return {
        videoRef,
        scannerActive,
        scannerBusy,
        scannerSupported,
        scannerError,
        scannerStatus,
        manualQrPayload,
        startScanner,
        stopScanner,
        submitManualPayload,
    };
}
