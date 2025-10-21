/**
 * Parses the scanned QR code data to extract the verification code.
 * It handles both direct URLs and our custom `haiacolo://` scheme.
 * @param {string} qrData The raw string data from the scanned QR code.
 * @returns {string|null} The extracted code, or null if not found.
 */
function getCodeFromQrData(qrData) {
  if (!qrData) return null;

  // Handle custom scheme: haiacolo://verify?code=...
  if (qrData.startsWith("haiacolo://verify")) {
    try {
      const url = new URL(qrData.replace("haiacolo://", "https://"));
      return url.searchParams.get("code");
    } catch (e) {
      return null; // Invalid URL format
    }
  }

  // Fallback for standard HTTPS URLs: https://.../verify.html?code=...
  if (qrData.startsWith("https://")) {
    try {
      const url = new URL(qrData);
      // If it's a valid URL, we ONLY care about the 'code' param.
      // If it's missing, we consider the QR data invalid.
      return url.searchParams.get("code"); // Returns null if not found, which is correct.
    } catch (e) {
      // Not a valid URL, it might be the code itself, so proceed to the final fallback.
    }
  }

  // Final fallback: assume the data is the code itself.
  return qrData;
}