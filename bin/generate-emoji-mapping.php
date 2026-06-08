<?php

declare(strict_types=1);

/**
 * Generates emoji-aliases.js and EmojiMapping.php from the canonical
 * source file assets/emoji-aliases.json.
 *
 * Usage: php bin/generate-emoji-mapping.php
 */

$jsonPath = __DIR__.'/../assets/emoji-aliases.json';
$jsPath = __DIR__.'/../assets/emoji-aliases.js';
$phpPath = __DIR__.'/../src/Service/EmojiMapping.php';

if (!file_exists($jsonPath)) {
    fwrite(STDERR, "Source file not found: $jsonPath\n");
    exit(1);
}

$data = json_decode(file_get_contents($jsonPath), true, 512, JSON_THROW_ON_ERROR);

// ---- Generate JS ----
$js = "// Auto-generated from emoji-aliases.json -- do not edit directly\n";
$js .= "// Run `php bin/generate-emoji-mapping.php` to regenerate.\n";
$js .= "export const EMOJI_ALIASES = {\n";
foreach ($data as $shortcode => $emoji) {
    $escapedEmoji = json_encode($emoji, JSON_UNESCAPED_UNICODE);
    $js .= sprintf("    '%s': %s,\n", $shortcode, $escapedEmoji);
}
$js .= "};\n";

file_put_contents($jsPath, $js);
echo "✓ Generated $jsPath (".count($data)." entries)\n";

// ---- Generate PHP ----
$php = "<?php\n\n";
$php .= "declare(strict_types=1);\n\n";
$php .= "// Auto-generated from emoji-aliases.json -- do not edit directly\n";
$php .= "// Run `php bin/generate-emoji-mapping.php` to regenerate.\n\n";
$php .= "namespace App\\Service;\n\n";
$php .= "class EmojiMapping\n";
$php .= "{\n";
$php .= "    public const MAPPING = [\n";
foreach ($data as $shortcode => $emoji) {
    $escapedEmoji = json_encode($emoji, JSON_UNESCAPED_UNICODE);
    $php .= sprintf("        '%s' => %s,\n", $shortcode, $escapedEmoji);
}
$php .= "    ];\n\n";
$php .= "    public static function getShortcode(string \$emoji): ?string\n";
$php .= "    {\n";
$php .= "        \$shortcode = array_search(\$emoji, self::MAPPING, true);\n\n";
$php .= "        return \$shortcode ? (string)\$shortcode : null;\n";
$php .= "    }\n";
$php .= "}\n";

file_put_contents($phpPath, $php);
echo "✓ Generated $phpPath (".count($data)." entries)\n";

echo "\nDone.\n";
