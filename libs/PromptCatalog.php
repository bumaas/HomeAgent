<?php

declare(strict_types=1);

/**
 * Vorlagen für ganze Abläufe (MCP-Prompts, Backlog B63): „Haus verlassen“, „Gute Nacht“, „Energiebericht“. Der
 * Anwender wählt sie im Client aus, das Modell bekommt einen fertigen Auftrag mit den passenden Aufrufen von get_state.
 * Angeboten wird nur, was die Freigabeliste hergibt; ein Nur-Lese-Zugang bekommt keine Frage nach dem Schalten.
 * Ohne Symcon-Abhängigkeit, die Übersetzung kommt von außen.
 */
final class PromptCatalog
{
    /** Was „Haus verlassen“ und „Gute Nacht“ prüfen: Typ => Beschreibung (englischer Schlüssel für die Übersetzung). */
    private const CHECKS = [
        'window' => 'windows (get_state type=window, active_only=true)',
        'door'   => 'doors (get_state type=door, active_only=true)',
        'light'  => 'lights (get_state type=light, active_only=true)',
        'media'  => 'media players (get_state type=media, active_only=true)',
    ];

    /** @var list<string> */
    private array $checks;

    /** @var callable(string): string */
    private $translate;

    /**
     * @param list<string> $types Typen der freigegebenen Geräte
     * @param bool $energy es gibt eine Energiebilanz oder ein Gerät mit Zähler
     * @param bool $readOnly der Zugang darf nur abfragen
     * @param callable(string): string $translate
     */
    public function __construct(array $types, private bool $energy, private bool $readOnly, callable $translate)
    {
        $this->checks = array_values(array_intersect(array_keys(self::CHECKS), $types));
        $this->translate = $translate;
    }

    /** @return list<array{name: string, title: string, description: string}> */
    public function list(): array
    {
        $prompts = [];
        foreach ($this->available() as $name => [$title, $description]) {
            $prompts[] = ['name' => $name, 'title' => $this->t($title), 'description' => $this->t($description)];
        }
        return $prompts;
    }

    /** @return null|array{description: string, messages: list<array{role: string, content: array{type: string, text: string}}>} */
    public function get(string $name): ?array
    {
        $available = $this->available();
        if (!isset($available[$name])) {
            return null;
        }
        $lines = match ($name) {
            'leave_home'    => array_merge([$this->t('I am leaving the house. Check what is still open or on:')], $this->checkLines(), [
                $this->t('Name only what is open or on; if everything is closed and off, say so in one sentence.'),
                $this->readOnly ? '' : $this->t('Do not switch anything without asking me.'),
            ]),
            'good_night'    => array_merge([$this->t('I am going to bed. Check what is still open or on:')], $this->checkLines(), [
                $this->readOnly ? $this->t('Name only what is open or on.') : $this->t('Name what is open or on and ask whether you should switch it off or close it.'),
            ]),
            default         => [$this->t('Give me the energy report of yesterday: house consumption, self-sufficiency and the largest consumers (get_state period=yesterday).')],
        };
        return [
            'description' => $this->t($available[$name][1]),
            'messages'    => [['role' => 'user', 'content' => ['type' => 'text', 'text' => implode("\n", array_filter($lines, static fn (string $line): bool => $line !== ''))]]],
        ];
    }

    /** @return array<string, array{0: string, 1: string}> Name => Titel, Beschreibung (englische Schlüssel) */
    private function available(): array
    {
        $prompts = [];
        if ($this->checks !== []) {
            $prompts['leave_home'] = ['Leaving home', 'Checks what is still open or on.'];
            $prompts['good_night'] = ['Good night', 'Checks what is still open or on before going to bed.'];
        }
        if ($this->energy) {
            $prompts['energy_report'] = ['Energy report', 'Consumption, self-sufficiency and largest consumers of yesterday.'];
        }
        return $prompts;
    }

    /** @return list<string> */
    private function checkLines(): array
    {
        return array_map(fn (string $type): string => '- ' . $this->t(self::CHECKS[$type]), $this->checks);
    }

    private function t(string $text): string
    {
        return ($this->translate)($text);
    }
}
