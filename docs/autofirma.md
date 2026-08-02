# AutoFirma support

Documentate can sign generated PDF files with the local AutoFirma application. The feature is enabled per template: the **Sign and Download** action is shown only when the selected DOCX or ODT template contains a `[sign]` placeholder.

The generated PDF is downloaded by the browser, sent to AutoFirma as Base64, signed locally using PAdES and downloaded again as a signed PDF. The unsigned and signed document contents are not uploaded to an additional Documentate endpoint.

## Basic marker

Add this placeholder to the DOCX or ODT template:

```text
[sign]
```

This enables signing and uses the default visible signature rectangle:

- Last page.
- 72 points from the left edge.
- 72 points from the bottom edge.
- 240 points wide.
- 80 points high.

The placeholder itself is replaced with an empty value during template generation, so `[sign]` is not printed in the resulting document.

## Positioned signature

Use parameters when the signature must appear at a specific PDF location:

```text
[sign;page=2;x=72;y=72;width=240;height=80]
```

| Parameter | Meaning | Default |
|---|---|---:|
| `page` | PDF page number. Use `-1` for the last page. | `-1` |
| `x` | Horizontal offset in PDF points from the bottom-left corner. | `72` |
| `y` | Vertical offset in PDF points from the bottom-left corner. | `72` |
| `width` | Visible signature rectangle width in PDF points. | `240` |
| `height` | Visible signature rectangle height in PDF points. | `80` |
| `text` | Optional AutoFirma layer 2 text. | Signer common name and signing date |

Coordinates use the PDF coordinate system, whose origin is the bottom-left corner. One point is 1/72 inch. Zero is a valid value for `x` and `y`.

Example for a signature at the bottom-right area of an A4 page:

```text
[sign;page=-1;x=300;y=40;width=220;height=70]
```

## Signing workflow

1. Save the Documentate document.
2. Select **Sign and Download** in the document actions box.
3. Select a certificate in AutoFirma.
4. The browser downloads a file named `<document-slug>-<post-id>-signed.pdf`.

The operation is cancelled without an error message when the certificate dialog is cancelled by the user.

## Requirements

- AutoFirma installed and registered for browser communication.
- A valid signing certificate available to AutoFirma.
- A configured PDF generation path in Documentate.
- A DOCX or ODT template containing `[sign]`.

The current implementation signs in the browser and downloads the result. It does not create a WordPress Media Library attachment for the signed PDF.

## Troubleshooting

When the signing action is not shown, verify that the active document type uses the template containing `[sign]`. The marker must use the exact placeholder name `sign`.

When AutoFirma does not open, verify the local installation and browser protocol registration. Browser restrictions, remote desktop environments and managed devices can prevent the native application from being launched.

When the visible signature appears outside the expected area, adjust `x`, `y`, `width` and `height`. These values refer to the final PDF, not to centimetres or the DOCX/ODT page coordinates.
