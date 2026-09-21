<?php

namespace App\Enums;

/**
 * How an offer letter template's wording is maintained: written in the panel's rich-text editor, or
 * as a Word (.docx) file that admins download, edit in Word and upload again.
 */
enum OfferLetterTemplateFormat: string
{
    case RichText = 'rich_text';
    case Word = 'word';

    public function label(): string
    {
        return match ($this) {
            self::RichText => 'Rich text (edit in the panel)',
            self::Word => 'Word file (download, edit, upload)',
        };
    }
}
