<?php

namespace App\Core\Http\Crud;

use Closure;

/**
 * Descrierea unui câmp pentru ecranele generice de listă/formular.
 * Tipuri: text, email, tel, number, date, time, url, textarea, select, multiselect, checkbox, color, hours.
 */
final class Field
{
    public ?Closure $options = null;

    public ?Closure $display = null;

    public array $rules = [];

    public bool $inList = false;

    public bool $searchable = false;

    public bool $full = false;

    public ?string $hint = null;

    public ?string $relation = null;

    public bool $required = false;

    public function __construct(public string $name, public string $label, public string $type = 'text') {}

    public static function make(string $name, string $label, string $type = 'text'): self
    {
        return new self($name, $label, $type);
    }

    public function rules(array|string $rules): self
    {
        $this->rules = is_string($rules) ? explode('|', $rules) : $rules;
        $this->required = in_array('required', $this->rules, true);

        return $this;
    }

    /** @param  Closure(): array<string|int,string>|array<string|int,string>  $options */
    public function options(Closure|array $options): self
    {
        $this->options = $options instanceof Closure ? $options : fn () => $options;

        return $this;
    }

    public function list(?Closure $display = null): self
    {
        $this->inList = true;
        $this->display = $display;

        return $this;
    }

    public function searchable(): self
    {
        $this->searchable = true;

        return $this;
    }

    public function full(): self
    {
        $this->full = true;

        return $this;
    }

    public function hint(string $hint): self
    {
        $this->hint = $hint;

        return $this;
    }

    /** Pentru multiselect: numele relației belongsToMany sincronizate. */
    public function relation(string $relation): self
    {
        $this->relation = $relation;

        return $this;
    }

    public function resolvedOptions(): array
    {
        return $this->options ? ($this->options)() : [];
    }
}
