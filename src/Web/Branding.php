<?php

declare(strict_types=1);

namespace App\Web;

/**
 * Herkunftshinweis «powered by VylarisContract» in der Seitenleiste.
 *
 * Der Hinweis ist nicht konfigurierbar (keine Einstellung, kein Mandanten-CSS). Zusätzlich wird er beim Ausliefern jeder
 * Seite mit Seitenleiste geprüft und bei Fehlen oder Veränderung automatisch in der Originalform wiederhergestellt.
 * Das schützt vor versehentlichem Entfernen und vor Änderungen über die Oberfläche. Wer Dateizugriff auf den Server hat,
 * kann den Code technisch immer ändern – der Schutz dort ist die Lizenz, nicht die Software.
 */
final class Branding
{
    public const TEXT = 'powered by VylarisContract';
    public const URL = 'https://vylaris.ch';

    public static function html(): string
    {
        return '<div class="vmark" data-vylaris-mark><a href="' . self::URL . '" target="_blank" rel="noopener">' . self::TEXT . '</a></div>';
    }

    /** Stellt sicher, dass eine Seite mit Seitenleiste genau den Originalhinweis enthält. */
    public static function enforce(string $html): string
    {
        if (!str_contains($html, '<aside class="sidebar"')) {
            return $html;
        }
        $mark = self::html();
        if (substr_count($html, 'data-vylaris-mark') === 1 && str_contains($html, $mark)) {
            return $html;
        }
        $html = (string) preg_replace('#<div[^>]*data-vylaris-mark[^>]*>.*?</div>#s', '', $html);
        $pos = strrpos($html, '</aside>');
        if ($pos !== false) {
            return substr($html, 0, $pos) . $mark . substr($html, $pos);
        }
        $pos = strrpos($html, '</body>');
        return $pos === false ? $html . $mark : substr($html, 0, $pos) . '<div class="vmark-fixed">' . $mark . '</div>' . substr($html, $pos);
    }
}
