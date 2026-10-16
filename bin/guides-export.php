<?php

declare(strict_types=1);

// Schreibt alle Anleitungen aus src/Web/HelpTopics.php nach docs/ANLEITUNGEN.md.   Aufruf: php bin/guides-export.php
require __DIR__ . '/../src/autoload.php';

use App\Web\HelpTopics;

$out = "# Anleitungen\n\n*Diese Datei wird aus `src/Web/HelpTopics.php` erzeugt (`php bin/guides-export.php`). In der Anwendung stehen dieselben Texte unter «Hilfe & Anleitungen».*\n\n";
$toc = '';
$body = '';
foreach (HelpTopics::GROUPS as $gk => $gl) {
    $items = array_filter(HelpTopics::all(), static fn (array $t): bool => $t['group'] === $gk);
    if ($items === []) {
        continue;
    }
    $toc .= "- **$gl**\n";
    $body .= "\n## $gl\n";
    foreach ($items as $slug => $t) {
        $toc .= "  - [{$t['title']}](#$slug)\n";
        $body .= "\n<a id=\"$slug\"></a>\n### {$t['title']}\n\n_{$t['summary']}_  \nDauer: ca. {$t['time']} · für " . HelpTopics::AUDIENCES[$t['audience']] . "\n\n";
        foreach ($t['steps'] as $i => [$title, $text]) {
            $body .= ($i + 1) . ". **$title** – $text\n";
        }
        if ($t['tips'] !== []) {
            $body .= "\n> **Gut zu wissen**\n";
            foreach ($t['tips'] as $tip) {
                $body .= "> - $tip\n";
            }
        }
    }
}
file_put_contents(__DIR__ . '/../docs/ANLEITUNGEN.md', $out . $toc . $body);
echo "docs/ANLEITUNGEN.md geschrieben\n";
