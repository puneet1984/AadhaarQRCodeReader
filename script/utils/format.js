/**
 * @module utils/format
 * @description Formatting and extraction helpers for Aadhaar card display data.
 */

/**
 * Formats a raw Aadhaar number (or its last-4-digit suffix) into the standard
 * masked display string shown on the physical Aadhaar card.
 *
 * Only the last four digits are ever shown; the first eight are replaced with
 * `X` groups to protect the cardholder's full number.
 *
 * @param {string} aadhaarNumber - The full 12-digit Aadhaar number **or** just
 *   the last-4-digit string extracted from the QR payload.
 * @returns {string} Masked display string in the form `"XXXX XXXX ####"`.
 *
 * @example
 * formatAadhaarNumber("123456781234"); // → "XXXX XXXX 1234"
 * formatAadhaarNumber("1234");         // → "XXXX XXXX 1234"
 * formatAadhaarNumber("");             // → "XXXX XXXX ????"
 */
export function formatAadhaarNumber(aadhaarNumber) {
    const last4 = (aadhaarNumber || "????").slice(-4);
    return `XXXX XXXX ${last4}`;
}

/**
 * Extracts the embedded comment string from a JPEG 2000 (ISO 15444-1) codestream.
 *
 * JPEG 2000 comment (CMT) markers have the binary layout:
 * ```
 * FF 64  — marker code
 * LL LL  — 2-byte big-endian segment length (includes the 4 header bytes)
 * RR RR  — 2-byte registration value (0x0001 = Latin-1 text, 0x0000 = binary)
 * <data> — comment bytes (length − 2 bytes)
 * ```
 * This function scans the byte array for the first `FF 64` marker, reads the
 * payload, strips any embedded null characters, and returns it as a string.
 *
 * @param {Uint8Array} bytes - Raw JPEG 2000 codestream bytes.
 * @returns {string | null} Decoded comment text, or `null` if no CMT marker
 *   is present in the stream.
 *
 * @example
 * const comment = extractComment(jp2Bytes);
 * if (comment) console.log("JP2 comment:", comment);
 */
export function extractComment(bytes) {
    for (let i = 0; i < bytes.length - 4; i++) {
        if (bytes[i] === 0xff && bytes[i + 1] === 0x64) {
            const len = (bytes[i + 2] << 8) | bytes[i + 3];
            return new TextDecoder()
                .decode(bytes.slice(i + 6, i + 2 + len))
                .replace(/\0/g, "");
        }
    }
    return null;
}

/**
 * Transforms decoded Aadhaar data into a clean, normalized, JSON-serializable object.
 *
 * Standardizes fields, formats dates, organizes address parts, embeds the
 * photo as a Base64 data URL (if decoded), and excludes raw TypedArrays so
 * the output can be safely stringified and sent to downstream APIs.
 *
 * @param {import("../modules/aadhaar.js").AadhaarData} userData - Decoded Aadhaar fields.
 * @param {string | null} [photoBase64=null] - Base64 PNG data-URL of the photo.
 * @param {string | null} [rawQr=null] - Raw QR string payload.
 * @returns {Record<string, any>} Clean, serializable JSON object.
 */
export function formatAadhaarJson(userData, photoBase64 = null, rawQr = null) {
    const formattedAadhaar = formatAadhaarNumber(userData.aadhaar_num);
    const last4 = (userData.aadhaar_num || "").slice(-4);

    const fullAddress = [
        userData.careof,
        userData.house,
        userData.street,
        userData.landmark,
        userData.location,
        userData.vtc,
        userData.postoffice,
        userData.subdistrict,
        userData.district,
        userData.state,
        userData.pincode,
    ]
        .filter(Boolean)
        .join(", ");

    return {
        aadhaarNumber: formattedAadhaar,
        aadhaarLast4: last4 || null,
        name: userData.name || null,
        dob: userData.dob || null,
        gender:
            userData.gender === "M"
                ? "Male"
                : userData.gender === "F"
                  ? "Female"
                  : userData.gender || null,
        mobileLast4: userData.last_4_digits_mobile_no || null,
        email: userData.email || null,
        address: {
            careof: userData.careof || null,
            house: userData.house || null,
            street: userData.street || null,
            landmark: userData.landmark || null,
            location: userData.location || null,
            vtc: userData.vtc || null,
            postoffice: userData.postoffice || null,
            subdistrict: userData.subdistrict || null,
            district: userData.district || null,
            state: userData.state || null,
            pincode: userData.pincode || null,
            full: fullAddress || null,
        },
        metadata: {
            referenceId: userData.referenceid || null,
            referenceDate:
                userData.reference_date instanceof Date &&
                !isNaN(userData.reference_date)
                    ? userData.reference_date.toISOString()
                    : null,
            version: userData.version || null,
            emailMobileStatus: userData.email_mobile_status || null,
        },
        photoBase64: photoBase64 || null,
        rawQr: rawQr || null,
    };
}
