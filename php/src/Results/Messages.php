<?php

declare(strict_types=1);

namespace PrintPreflight\Results;

/**
 * Default customer-facing message templates. Profiles can override every
 * template per rule (`messages` in the rule configuration).
 */
final class Messages
{
    public const CATALOGS = [
        'de' => [
            'document.unreadable' => 'Die Datei konnte nicht gelesen werden.',
            'document.invalid' => 'Die Datei ist keine gültige PDF-Datei oder beschädigt.',
            'document.password_required' => 'Die PDF-Datei ist passwortgeschützt. Bitte laden Sie eine Datei ohne Kennwort hoch.',
            'document.unsupported_encryption' => 'Die Verschlüsselung der PDF-Datei wird nicht unterstützt.',
            'document.too_large' => 'Die Datei ist zu groß.',
            'document.limit_exceeded' => 'Die Datei ist zu komplex, um automatisch geprüft zu werden.',
            'document.internal' => 'Bei der Prüfung der Datei ist ein interner Fehler aufgetreten.',
            'document.not_analyzed' => 'Die Datei vom Typ „{file_type}“ kann nicht automatisch geprüft werden.',
            'file_type.not_allowed' => 'Der Dateityp „{file_type}“ ist nicht zulässig. Erlaubt: {allowed}.',
            'page_size.mismatch' => 'Seite {page}: Das Format {actual_width} × {actual_height} mm ({box}) entspricht nicht dem geforderten Format {expected} mm.',
            'page_size.orientation' => 'Seite {page}: Die Seite muss im {expected_orientation} angelegt sein.',
            'page_size.too_small' => 'Seite {page}: Das Format {actual_width} × {actual_height} mm ({box}) ist kleiner als das Mindestformat {min_width} × {min_height} mm.',
            'page_size.too_large' => 'Seite {page}: Das Format {actual_width} × {actual_height} mm ({box}) ist größer als das Höchstformat {max_width} × {max_height} mm.',
            'page_size.box_missing' => 'Seite {page}: Die {box} ist nicht definiert.',
            'page_count.exact' => 'Das Dokument muss genau {expected} Seite(n) haben, hat aber {actual}.',
            'page_count.too_few' => 'Das Dokument muss mindestens {min} Seite(n) haben, hat aber {actual}.',
            'page_count.too_many' => 'Das Dokument darf höchstens {max} Seite(n) haben, hat aber {actual}.',
            'page_count.multiple_of' => 'Die Seitenzahl muss durch {multiple} teilbar sein (aktuell {actual}).',
            'box.missing' => 'Seite {page}: Die {box} ist nicht definiert.',
            'bleed.box_missing' => 'Seite {page}: Für die Beschnittprüfung fehlt die {box}.',
            'bleed.insufficient' => 'Seite {page}: Die Beschnittzugabe beträgt nur {actual_bleed} mm, erforderlich sind {min_bleed} mm.',
            'fonts.not_embedded' => 'Seite {page}: Die Schrift „{font}“ ist nicht eingebettet.',
            'fonts.invalid_embedding' => 'Seite {page}: Die Schrift „{font}“ ist fehlerhaft eingebettet.',
            'fonts.missing_resource' => 'Seite {page}: Der Text verwendet eine nicht definierte Schrift.',
            'fonts.analysis_incomplete' => 'Seite {page}: Die Schriften konnten nicht vollständig geprüft werden.',
            'color_pages.undetermined' => 'Die Anzahl farbiger Seiten konnte nicht zuverlässig ermittelt werden.',
            'color_pages.too_many_color' => 'Das Dokument enthält {actual} farbige Seite(n), erlaubt sind höchstens {max}.',
            'color_pages.too_few_color' => 'Das Dokument enthält {actual} farbige Seite(n), erforderlich sind mindestens {min}.',
            'color_pages.too_many_grayscale' => 'Das Dokument enthält {actual} Schwarz-Weiß-Seite(n), erlaubt sind höchstens {max}.',
            'color_pages.too_few_grayscale' => 'Das Dokument enthält {actual} Schwarz-Weiß-Seite(n), erforderlich sind mindestens {min}.',
            'image_resolution.too_low' => 'Seite {page}: Ein Bild hat nur {dpi} dpi effektive Auflösung, erforderlich sind mindestens {min_dpi} dpi.',
            'image_resolution.too_high' => 'Seite {page}: Ein Bild hat {dpi} dpi effektive Auflösung, zulässig sind höchstens {max_dpi} dpi.',
            'image_resolution.analysis_incomplete' => 'Seite {page}: Die Bilder konnten nicht vollständig geprüft werden.',
            'color_spaces.forbidden' => 'Seite {page}: Der Farbraum {family} ({category}) ist nicht zulässig.',
            'color_spaces.spot_not_allowed' => 'Seite {page}: Die Sonderfarbe „{spot}“ ist nicht zulässig.',
            'color_spaces.too_many_spot_colors' => 'Das Dokument enthält {actual} Sonderfarben ({spots}), zulässig sind höchstens {max}.',
            'color_spaces.analysis_incomplete' => 'Seite {page}: Die Farben konnten nicht vollständig geprüft werden.',
            'pdf_properties.encrypted' => 'Die PDF-Datei ist verschlüsselt. Bitte laden Sie eine Datei ohne Sicherheitseinstellungen hoch.',
            'pdf_properties.pdfx_not_declared' => 'Die Datei ist nicht als {expected} gekennzeichnet.',
            'pdf_properties.version_too_high' => 'Die PDF-Version {actual} ist zu neu, zulässig ist höchstens {max}.',
        ],
        'en' => [
            'document.unreadable' => 'The file could not be read.',
            'document.invalid' => 'The file is not a valid PDF file or is damaged.',
            'document.password_required' => 'The PDF file is password protected. Please upload a file without a password.',
            'document.unsupported_encryption' => 'The encryption of the PDF file is not supported.',
            'document.too_large' => 'The file is too large.',
            'document.limit_exceeded' => 'The file is too complex to be checked automatically.',
            'document.internal' => 'An internal error occurred while checking the file.',
            'document.not_analyzed' => 'Files of type "{file_type}" cannot be checked automatically.',
            'file_type.not_allowed' => 'The file type "{file_type}" is not allowed. Allowed: {allowed}.',
            'page_size.mismatch' => 'Page {page}: The size {actual_width} × {actual_height} mm ({box}) does not match the required size {expected} mm.',
            'page_size.orientation' => 'Page {page}: The page must be in {expected_orientation} orientation.',
            'page_size.too_small' => 'Page {page}: The size {actual_width} × {actual_height} mm ({box}) is smaller than the minimum size {min_width} × {min_height} mm.',
            'page_size.too_large' => 'Page {page}: The size {actual_width} × {actual_height} mm ({box}) is larger than the maximum size {max_width} × {max_height} mm.',
            'page_size.box_missing' => 'Page {page}: The {box} is not defined.',
            'page_count.exact' => 'The document must have exactly {expected} page(s) but has {actual}.',
            'page_count.too_few' => 'The document must have at least {min} page(s) but has {actual}.',
            'page_count.too_many' => 'The document may have at most {max} page(s) but has {actual}.',
            'page_count.multiple_of' => 'The page count must be a multiple of {multiple} (currently {actual}).',
            'box.missing' => 'Page {page}: The {box} is not defined.',
            'bleed.box_missing' => 'Page {page}: The {box} required for the bleed check is missing.',
            'bleed.insufficient' => 'Page {page}: The bleed is only {actual_bleed} mm; {min_bleed} mm are required.',
            'fonts.not_embedded' => 'Page {page}: The font "{font}" is not embedded.',
            'fonts.invalid_embedding' => 'Page {page}: The font "{font}" is not embedded correctly.',
            'fonts.missing_resource' => 'Page {page}: Text uses an undefined font.',
            'fonts.analysis_incomplete' => 'Page {page}: The fonts could not be checked completely.',
            'color_pages.undetermined' => 'The number of colour pages could not be determined reliably.',
            'color_pages.too_many_color' => 'The document contains {actual} colour page(s); at most {max} are allowed.',
            'color_pages.too_few_color' => 'The document contains {actual} colour page(s); at least {min} are required.',
            'color_pages.too_many_grayscale' => 'The document contains {actual} black-and-white page(s); at most {max} are allowed.',
            'color_pages.too_few_grayscale' => 'The document contains {actual} black-and-white page(s); at least {min} are required.',
            'image_resolution.too_low' => 'Page {page}: An image has an effective resolution of only {dpi} dpi; at least {min_dpi} dpi are required.',
            'image_resolution.too_high' => 'Page {page}: An image has an effective resolution of {dpi} dpi; at most {max_dpi} dpi are allowed.',
            'image_resolution.analysis_incomplete' => 'Page {page}: The images could not be checked completely.',
            'color_spaces.forbidden' => 'Page {page}: The colour space {family} ({category}) is not allowed.',
            'color_spaces.spot_not_allowed' => 'Page {page}: The spot colour "{spot}" is not allowed.',
            'color_spaces.too_many_spot_colors' => 'The document contains {actual} spot colours ({spots}); at most {max} are allowed.',
            'color_spaces.analysis_incomplete' => 'Page {page}: The colours could not be checked completely.',
            'pdf_properties.encrypted' => 'The PDF file is encrypted. Please upload a file without security settings.',
            'pdf_properties.pdfx_not_declared' => 'The file is not identified as {expected}.',
            'pdf_properties.version_too_high' => 'PDF version {actual} is too new; at most {max} is allowed.',
        ],
    ];

    /** Translations of orientation values used in messages. */
    public const ORIENTATIONS = [
        'de' => ['portrait' => 'Hochformat', 'landscape' => 'Querformat'],
        'en' => ['portrait' => 'portrait', 'landscape' => 'landscape'],
    ];
}
