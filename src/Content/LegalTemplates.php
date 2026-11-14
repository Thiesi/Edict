<?php

declare(strict_types=1);

namespace Edict\Content;

/**
 * Starting points for the two pages a German site must have, written for how Edict
 * actually works. Everything in [brackets] must be filled in, and the result checked:
 * this is a template, not legal advice.
 */
final class LegalTemplates
{
    /**
     * @return array{title: string, slug: string, body: array<string, mixed>}|null
     */
    public static function get(string $which): ?array
    {
        return match ($which) {
            'imprint' => ['title' => 'Impressum', 'slug' => 'impressum', 'body' => self::doc(self::IMPRINT)],
            'privacy' => ['title' => 'Datenschutzerklärung', 'slug' => 'datenschutz', 'body' => self::doc(self::PRIVACY)],
            default => null,
        };
    }

    private const IMPRINT = [
        ['p', 'Vorlage: Alles in [eckigen Klammern] ausfüllen und das Ergebnis prüfen, bevor die Seite veröffentlicht wird. Diesen Absatz danach löschen.'],
        ['h2', 'Angaben gemäß § 5 DDG'],
        ['p', '[Vor- und Nachname]'],
        ['p', '[Straße und Hausnummer]'],
        ['p', '[Postleitzahl und Ort]'],
        ['h2', 'Kontakt'],
        ['p', 'E-Mail: [Adresse]'],
        ['h2', 'Verantwortlich für den Inhalt nach § 18 Abs. 2 MStV'],
        ['p', '[Vor- und Nachname, Anschrift wie oben]'],
        ['h2', 'Haftung für Inhalte und Links'],
        ['p', 'Die Inhalte dieser Seite werden mit Sorgfalt erstellt. Für Inhalte verlinkter fremder Seiten sind ausschließlich deren Betreiber verantwortlich. Bei Bekanntwerden von Rechtsverletzungen werden entsprechende Inhalte oder Links umgehend entfernt.'],
        ['h2', 'Kommentare'],
        ['p', 'Für Kommentare sind ihre Verfasser verantwortlich. Rechtswidrige Kommentare werden nach Kenntnis entfernt.'],
    ];

    private const PRIVACY = [
        ['p', 'Vorlage: Alles in [eckigen Klammern] ausfüllen und das Ergebnis prüfen, bevor die Seite veröffentlicht wird. Sie beschreibt, wie Edict arbeitet; wer etwas ergänzt (etwa weitere Dienste), muss sie anpassen. Diesen Absatz danach löschen.'],
        ['h2', 'Verantwortlicher'],
        ['p', '[Vor- und Nachname, Anschrift, E-Mail-Adresse], siehe Impressum.'],
        ['h2', 'Hosting und Server-Protokolle'],
        ['p', 'Diese Website läuft auf einem eigenen Server in [Land/Rechenzentrum]. Beim Aufruf jeder Seite verarbeitet der Webserver technisch notwendige Daten: IP-Adresse, Datum und Uhrzeit, aufgerufene Adresse, übertragene Datenmenge, Browser-Kennung und verweisende Seite. Sie werden in Protokolldateien gespeichert, um den Betrieb zu sichern und Angriffe abzuwehren (Art. 6 Abs. 1 lit. f DSGVO), und nach [Anzahl] Tagen gelöscht.'],
        ['h2', 'Keine Tracker, keine fremden Dienste beim Lesen'],
        ['p', 'Diese Website verwendet keine Analyse- oder Werbedienste und bindet beim Lesen nichts von fremden Servern ein: Schriften, Bilder, Videos und Skripte kommen von diesem Server.'],
        ['h2', 'Cookies'],
        ['p', 'Wer liest, bekommt keine Cookies. Wer sich anmeldet, bekommt ein Sitzungs-Cookie, das die Anmeldung hält, und während der Anmeldung kurzzeitig ein verschlüsseltes Cookie, das den Ablauf absichert. Beide sind technisch notwendig (§ 25 Abs. 2 Nr. 2 TDDDG) und enthalten keine Daten für andere Zwecke.'],
        ['h2', 'Anmeldung mit Google oder Twitch'],
        ['p', 'Kommentieren und Liken setzt eine Anmeldung über Google oder Twitch voraus. Dabei wird man zur Seite des gewählten Anbieters weitergeleitet, der dafür eigene Daten verarbeitet; es gelten dessen Datenschutzhinweise. Diese Website erhält und speichert nur die Kennung des Kontos beim Anbieter und den Anzeigenamen, keine E-Mail-Adresse und keine weiteren Profildaten (Art. 6 Abs. 1 lit. b DSGVO). Anbieter: Google Ireland Limited, Gordon House, Barrow Street, Dublin 4, Irland; Twitch Interactive, Inc., 350 Bush Street, San Francisco, CA 94104, USA. [Bei Twitch: Hinweis auf die Datenübermittlung in die USA prüfen.]'],
        ['h2', 'Kommentare und Likes'],
        ['p', 'Kommentare werden mit dem Anzeigenamen, dem Zeitpunkt und dem Text gespeichert und öffentlich angezeigt. Likes werden dem Konto zugeordnet gespeichert, damit jedes Konto nur einmal liken kann; öffentlich ist nur die Zahl. Neue Kommentare melden wir uns selbst per E-Mail; dabei werden keine Adressen von Lesenden verwendet oder gespeichert.'],
        ['h2', 'Konto löschen'],
        ['p', 'Unter „Dein Konto“ lässt sich das Konto jederzeit löschen. Dabei werden die Verknüpfung mit Google oder Twitch, alle Sitzungen und alle Likes gelöscht; Kommentare bleiben als „[deleted user]“ stehen. [Prüfen, ob Kommentare stattdessen gelöscht werden sollen.]'],
        ['h2', 'Speicherdauer'],
        ['p', 'Kontodaten bleiben gespeichert, bis das Konto gelöscht wird. Sitzungen enden spätestens nach [Dauer]. Datensicherungen des Servers werden nach [Dauer] überschrieben.'],
        ['h2', 'Ihre Rechte'],
        ['p', 'Sie haben das Recht auf Auskunft (Art. 15 DSGVO), Berichtigung (Art. 16), Löschung (Art. 17), Einschränkung der Verarbeitung (Art. 18), Datenübertragbarkeit (Art. 20) und Widerspruch (Art. 21). Wenden Sie sich dazu an die oben genannte Adresse. Außerdem können Sie sich bei einer Datenschutz-Aufsichtsbehörde beschweren, etwa bei der für [Bundesland] zuständigen.'],
        ['p', 'Stand: [Datum]'],
    ];

    /**
     * @param list<array{0: string, 1: string}> $blocks
     *
     * @return array<string, mixed>
     */
    private static function doc(array $blocks): array
    {
        $content = [];
        foreach ($blocks as [$type, $text]) {
            $content[] = $type === 'h2'
                ? ['type' => 'heading', 'attrs' => ['level' => 2], 'content' => [['type' => 'text', 'text' => $text]]]
                : ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => $text]]];
        }

        return ['type' => 'doc', 'content' => $content];
    }
}
