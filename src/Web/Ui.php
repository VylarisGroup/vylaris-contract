<?php

declare(strict_types=1);

namespace App\Web;

/** Formularbausteine: konsistent, barrierearm (label/for, aria-describedby, Fehlertexte), alles escaped. */
final class Ui
{
    /** @param array<string,mixed> $old @param array<string,string> $errors */
    public function __construct(private readonly string $csrf, private readonly array $old = [], private readonly array $errors = [])
    {
    }

    public function csrf(): string
    {
        return '<input type="hidden" name="_csrf" value="' . e($this->csrf) . '">';
    }

    public function old(string $name, mixed $default = ''): string
    {
        $v = $this->old[$name] ?? $default;
        return is_scalar($v) || $v === null ? (string) $v : '';
    }

    public function error(string $name): ?string
    {
        return $this->errors[$name] ?? null;
    }

    /**
     * @param array{type?:string,value?:mixed,required?:bool,help?:string,rows?:int,options?:array<string|int,string>,autocomplete?:string,min?:string,max?:string,step?:string,maxlength?:int,placeholder?:string,inputmode?:string,pattern?:string,disabled?:bool,empty?:string,accept?:string} $o
     */
    public function field(string $name, string $label, array $o = []): string
    {
        $type = $o['type'] ?? 'text';
        $id = 'f_' . preg_replace('/[^a-z0-9_]/i', '_', $name);
        $value = $this->old($name, $o['value'] ?? '');
        $err = $this->error($name);
        $help = $o['help'] ?? null;
        $desc = [];
        if ($help !== null) {
            $desc[] = $id . '_h';
        }
        if ($err !== null) {
            $desc[] = $id . '_e';
        }
        $attr = ' id="' . $id . '" name="' . e($name) . '"'
            . (($o['required'] ?? false) ? ' required' : '')
            . (($o['disabled'] ?? false) ? ' disabled' : '')
            . ($err !== null ? ' aria-invalid="true"' : '')
            . ($desc !== [] ? ' aria-describedby="' . implode(' ', $desc) . '"' : '');
        foreach (['autocomplete', 'min', 'max', 'step', 'maxlength', 'placeholder', 'inputmode', 'pattern', 'accept'] as $a) {
            if (isset($o[$a])) {
                $attr .= ' ' . $a . '="' . e($o[$a]) . '"';
            }
        }
        if ($type === 'textarea') {
            $ctl = '<textarea class="input"' . $attr . ' rows="' . (int) ($o['rows'] ?? 4) . '">' . e($value) . '</textarea>';
        } elseif ($type === 'select') {
            $ctl = '<select class="input"' . $attr . '>';
            if (isset($o['empty'])) {
                $ctl .= '<option value="">' . e($o['empty']) . '</option>';
            }
            foreach (($o['options'] ?? []) as $k => $text) {
                $ctl .= '<option value="' . e($k) . '"' . ((string) $k === $value ? ' selected' : '') . '>' . e($text) . '</option>';
            }
            $ctl .= '</select>';
        } elseif ($type === 'checkbox') {
            $checked = $value === '1' || $value === 'on' ? ' checked' : '';
            return '<div class="field"><label class="check"><input type="checkbox" value="1"' . $attr . $checked . '> <span>' . e($label) . '</span></label>'
                . ($help !== null ? '<p class="help" id="' . $id . '_h">' . e($help) . '</p>' : '') . '</div>';
        } elseif ($type === 'file') {
            $ctl = '<input class="input" type="file"' . $attr . '>';
        } else {
            $val = $type === 'password' ? '' : ' value="' . e($value) . '"';
            $ctl = '<input class="input" type="' . e($type) . '"' . $attr . $val . '>';
        }
        return '<div class="field"><label class="label" for="' . $id . '">' . e($label) . (($o['required'] ?? false) ? ' <span class="req" aria-hidden="true">*</span>' : '') . '</label>'
            . $ctl
            . ($help !== null ? '<p class="help" id="' . $id . '_h">' . e($help) . '</p>' : '')
            . ($err !== null ? '<p class="field-error" id="' . $id . '_e" role="alert"><i class="bx bx-error-circle" aria-hidden="true"></i> ' . e($err) . '</p>' : '')
            . '</div>';
    }

    /** POST-Formular mit CSRF-Feld; $inner ist bereits sicheres HTML. */
    public function form(string $action, string $inner, bool $multipart = false, string $class = ''): string
    {
        return '<form method="post" action="' . e($action) . '"' . ($multipart ? ' enctype="multipart/form-data"' : '') . ($class !== '' ? ' class="' . e($class) . '"' : '') . '>' . $this->csrf() . $inner . '</form>';
    }

    /** Kleiner Ein-Klick-Button als eigenes Formular; $confirm löst ein Bestätigungsdialog (JS) aus. */
    public function action(string $action, string $label, string $icon = '', string $class = 'btn btn-secondary btn-sm', ?string $confirm = null, array $hidden = []): string
    {
        $h = '';
        foreach ($hidden as $k => $v) {
            $h .= '<input type="hidden" name="' . e($k) . '" value="' . e($v) . '">';
        }
        return '<form method="post" action="' . e($action) . '" class="inline">' . $this->csrf() . $h
            . '<button type="submit" class="' . e($class) . '"' . ($confirm !== null ? ' data-confirm="' . e($confirm) . '"' : '') . '>'
            . ($icon !== '' ? '<i class="bx ' . e($icon) . '" aria-hidden="true"></i> ' : '') . e($label) . '</button></form>';
    }
}
