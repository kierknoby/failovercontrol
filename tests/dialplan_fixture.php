<?php
declare(strict_types=1);

final class FakeExtensions
{
    public array $entries = [];
    public array $tags = [];
    public array $labels = [];

    public function add(string $context, string $extension, string $tag, object $command): void
    {
        if ($tag !== '' && isset($this->tags[$extension][$tag])) {
            throw new RuntimeException('Duplicate generated priority label: ' . $extension . '/' . $tag);
        }
        if ($tag !== '') {
            $this->tags[$extension][$tag] = true;
        }
        $this->labels[$extension][] = $tag;
        $this->entries[$extension][] = $command;
    }

    public function show(string $extension): string
    {
        $lines = ["[ Context 'app-failovercontrol' created by 'pbx_config' ]"];
        foreach ($this->entries[$extension] ?? [] as $index => $command) {
            $label = $this->labels[$extension][$index];
            $prefix = $index === 0 ? "'" . $extension . "' =>" : ($label !== '' ? '[' . $label . ']' : '');
            $lines[] = sprintf('  %-17s %d. %s [extensions_additional.conf:%d]', $prefix, $index + 1, $command->output(), $index + 1);
        }
        return implode("\n", $lines);
    }
}

// Allow the status contract to use FreePBX's real renderers when preloaded.
if (!class_exists('extension')) {
    class ext_noop
    {
        public function __construct(public string $data = '') {}
        public function output(): string { return 'Noop(' . $this->data . ')'; }
    }
    class extension
    {
        public function __construct(public string $data = '') {}
        public function output(): string { return $this->data; }
    }
    class ext_hangup
    {
        public function __construct(public string $data = '') {}
        public function output(): string { return 'Hangup(' . $this->data . ')'; }
    }
    class ext_setvar
    {
        public function __construct(public string $var, public string $value = '') {}
        public function output(): string { return 'Set(' . $this->var . '=' . $this->value . ')'; }
    }
    class ext_gotoif
    {
        public function __construct(public string $condition, public string $true, public string|false $false = false) {}
        public function output(): string { return 'GotoIf(' . $this->condition . '?' . $this->true . ($this->false !== false ? ':' . $this->false : '') . ')'; }
    }
    class ext_dial
    {
        public function __construct(public string $number, public string $options = 'tr') {}
        public function output(): string { return 'Dial(' . $this->number . ',' . $this->options . ')'; }
    }
    class ext_macro
    {
        public function __construct(public string $macro, public string $args = '') {}
        public function output(): string { return 'Gosub(macro-' . $this->macro . ',s,1(' . $this->args . '))'; }
    }
    class ext_goto
    {
        public function __construct(public string $priority, public string|false $extension = false, public string|false $context = false) {}
        public function output(): string
        {
            return 'Goto(' . ($this->context !== false ? $this->context . ',' : '') . ($this->extension !== false ? $this->extension . ',' : '') . $this->priority . ')';
        }
    }
}
