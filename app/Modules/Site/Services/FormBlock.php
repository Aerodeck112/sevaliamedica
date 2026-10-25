<?php

namespace App\Modules\Site\Services;

use App\Core\Settings\Settings;
use App\Modules\Clinic\Models\Specialty;
use App\Modules\Clinic\Models\StaffMember;
use App\Modules\Cms\Services\Html\Dom;
use DOMElement;
use DOMXPath;
use Illuminate\Support\Str;
use Illuminate\Support\ViewErrorBag;

/**
 * Formularele publice (contact, landing EMSELLA): markupul original Odoo se păstrează vizual, dar:
 *  - câmpurile primesc nume canonice și sunt trimise către ruta noastră (fără Odoo);
 *  - listele de specialități și medici vin din datele structurate (Clinică);
 *  - „Data nașterii” se afișează doar dacă setarea site.contact_birthdate e activă (implicit nu — minimizare);
 *  - protecție anti-spam: token semnat cu marcaj de timp (fără sesiune), câmp-capcană, timp minim, limitare.
 */
class FormBlock
{
    public const FORMS = ['contact', 'emsella'];

    /** Erorile și valorile anterioare, setate de controller la o retrimitere invalidă. */
    private ?ViewErrorBag $errors = null;

    private array $old = [];

    public function __construct(private readonly Settings $settings) {}

    public function withState(?ViewErrorBag $errors, array $old): void
    {
        $this->errors = $errors;
        $this->old = $old;
    }

    public function render(array $block, bool $preview = false): string
    {
        $form = in_array($block['config']['form'] ?? 'contact', self::FORMS, true) ? $block['config']['form'] : 'contact';
        [$doc, $root] = Dom::load($block['html'] ?? '');
        $xp = new DOMXPath($doc);
        $formEl = $xp->query('.//form', $root)->item(0);
        if (! $formEl instanceof DOMElement) {
            return $block['html'] ?? '';
        }

        foreach (iterator_to_array($formEl->attributes) as $attr) {
            if (str_starts_with($attr->name, 'data-') || $attr->name === 'enctype') {
                $formEl->removeAttribute($attr->name);
            }
        }
        $formEl->setAttribute('action', $preview ? '#' : route('site.form.submit', ['form' => $form]));
        $formEl->setAttribute('method', 'post');
        $formEl->setAttribute('novalidate', 'novalidate');
        $formEl->setAttribute('data-site-form', $form);

        // Câmpurile ascunse Odoo (destinatar e-mail, semnătură) nu mai sunt necesare.
        foreach (iterator_to_array($xp->query('.//*[contains(@class,"s_website_form_dnone")]', $formEl)) as $el) {
            $el->parentNode->removeChild($el);
        }

        $phoneCount = 0;
        $customText = 0;
        foreach (iterator_to_array($xp->query('.//*[contains(concat(" ", normalize-space(@class), " "), " s_website_form_field ")]', $formEl)) as $field) {
            /** @var DOMElement $field */
            $label = ($l = $xp->query('.//*[contains(@class,"s_website_form_label_content")]', $field)->item(0)) ? Dom::text($l) : '';
            $input = $xp->query('.//input|.//textarea|.//select', $field)->item(0);
            $type = $input instanceof DOMElement ? strtolower($input->tagName === 'input' ? ($input->getAttribute('type') ?: 'text') : $input->tagName) : '';
            $key = match (true) {
                $label === 'Nume' => 'name',
                str_starts_with(Str::ascii($label), 'Data nasterii') => 'birth_date',
                $label === 'Care e nevoia ta?' => 'specialties',
                str_starts_with($label, 'La cine doriți') => 'doctors',
                $label === 'Cum te putem contacta?' => 'contact',
                $label === 'Telefon' => ++$phoneCount === 1 ? 'phone' : 'phone_sms',
                $label === 'E-mail' || ($input instanceof DOMElement && $input->getAttribute('name') === 'email_from') => 'email',
                $label === 'Subiect' || ($input instanceof DOMElement && $input->getAttribute('name') === 'subject') => 'subject',
                $type === 'textarea' => 'message',
                $type === 'checkbox' && ($label === 'Custom Text' || $label === '') => 'consent',
                $label === 'Custom Text' => ++$customText === 1 ? 'message' : 'consent',
                default => null,
            };
            if ($key === null) {
                continue;
            }
            if ($key === 'birth_date' && ! $this->settings->get('site.contact_birthdate', false)) {
                $field->parentNode->removeChild($field);

                continue;
            }
            $field->setAttribute('data-field', $key);
            if ($key === 'specialties') {
                $this->replaceOptions($xp, $field, 'specialties[]', Specialty::active()->get()->mapWithKeys(fn ($s) => [$s->id => $s->name])->all());
            } elseif ($key === 'doctors') {
                $this->replaceOptions($xp, $field, 'doctors[]', StaffMember::onSite()->get()
                    ->mapWithKeys(fn ($s) => [$s->id => trim($s->display_name.($s->professional_title ? ' - '.$s->professional_title : ''))])->all());
            } elseif ($key === 'contact') {
                foreach ($xp->query('.//input[@type="checkbox"]', $field) as $cb) {
                    /** @var DOMElement $cb */
                    $cb->setAttribute('name', 'contact[]');
                    $cb->setAttribute('value', Str::lower(Str::ascii($cb->getAttribute('value'))));
                    $cb->removeAttribute('required');
                    $cb->removeAttribute('data-fill-with');
                    if (in_array($cb->getAttribute('value'), (array) ($this->old['contact'] ?? []), true)) {
                        $cb->setAttribute('checked', 'checked');
                    }
                }
            } else {
                foreach ($xp->query('.//input|.//textarea', $field) as $in) {
                    /** @var DOMElement $in */
                    $in->setAttribute('name', $key);
                    $in->removeAttribute('data-fill-with');
                    $in->removeAttribute('required');
                    if ($key === 'consent') {
                        $in->setAttribute('value', '1');
                        if (! empty($this->old['consent'])) {
                            $in->setAttribute('checked', 'checked');
                        }
                    } elseif ($key === 'email') {
                        $in->setAttribute('autocomplete', 'email');
                    } elseif (in_array($key, ['phone', 'phone_sms'], true)) {
                        $in->setAttribute('type', 'tel');
                        $in->setAttribute('autocomplete', 'tel');
                    } elseif ($key === 'name') {
                        $in->setAttribute('autocomplete', 'name');
                        $in->setAttribute('required', 'required');
                    }
                    if ($key !== 'consent' && isset($this->old[$key]) && is_string($this->old[$key])) {
                        $in->tagName === 'textarea' ? ($in->textContent = $this->old[$key]) : $in->setAttribute('value', $this->old[$key]);
                    }
                }
            }
            if ($this->errors?->has($key === 'phone_sms' ? 'phone' : $key)) {
                $msg = $doc->createElement('div');
                $msg->setAttribute('class', 'invalid-feedback d-block');
                $msg->setAttribute('role', 'alert');
                $msg->textContent = $this->errors->first($key === 'phone_sms' ? 'phone' : $key);
                $field->appendChild($msg);
            }
        }

        // Butonul „Trimite” devine un buton real de trimitere (aceleași clase).
        foreach (iterator_to_array($xp->query('.//a[contains(@class,"s_website_form_send")]', $formEl)) as $a) {
            /** @var DOMElement $a */
            $button = $doc->createElement('button');
            $button->setAttribute('type', 'submit');
            $button->setAttribute('class', $a->getAttribute('class'));
            $button->textContent = trim(str_replace("\u{200B}", '', $a->textContent));
            $a->parentNode->replaceChild($button, $a);
        }

        if (! $preview) {
            $anchor = $formEl->firstChild;
            foreach (FormToken::hiddenFields($form) as $name => $value) {
                $input = $doc->createElement('input');
                $input->setAttribute('type', 'hidden');
                $input->setAttribute('name', $name);
                $input->setAttribute('value', $value);
                $formEl->insertBefore($input, $anchor);
            }
            // Câmp-capcană pentru roboți: ascuns vizual și pentru cititoarele de ecran, completarea lui respinge mesajul.
            $trap = $doc->createElement('div');
            $trap->setAttribute('class', 'visually-hidden');
            $trap->setAttribute('aria-hidden', 'true');
            $trapLabel = $doc->createElement('label', 'Nu completați acest câmp');
            $trapLabel->setAttribute('for', 'website-'.$form);
            $trapInput = $doc->createElement('input');
            foreach (['type' => 'text', 'name' => FormToken::HONEYPOT, 'id' => 'website-'.$form, 'tabindex' => '-1', 'autocomplete' => 'off'] as $k => $v) {
                $trapInput->setAttribute($k, $v);
            }
            $trap->appendChild($trapLabel);
            $trap->appendChild($trapInput);
            $formEl->insertBefore($trap, $anchor);
        }
        if ($this->errors?->any()) {
            $alert = $doc->createElement('div');
            $alert->setAttribute('class', 'alert alert-danger');
            $alert->setAttribute('role', 'alert');
            $alert->textContent = $this->errors->has('form') ? $this->errors->first('form') : 'Vă rugăm să verificați câmpurile marcate.';
            $formEl->insertBefore($alert, $formEl->firstChild);
        }

        return Dom::inner($root);
    }

    /** Înlocuiește opțiunile unei liste de bife păstrând markupul primei opțiuni. */
    private function replaceOptions(DOMXPath $xp, DOMElement $field, string $name, array $options): void
    {
        $container = $xp->query('.//*[contains(@class,"s_website_form_multiple")]', $field)->item(0);
        $first = $container ? $xp->query('./*', $container)->item(0) : null;
        if (! $container instanceof DOMElement || ! $first instanceof DOMElement) {
            return;
        }
        $template = $first->cloneNode(true);
        while ($container->firstChild) {
            $container->removeChild($container->firstChild);
        }
        $selected = array_map('intval', (array) ($this->old[rtrim($name, '[]')] ?? []));
        $base = Str::slug($name);
        foreach ($options as $value => $label) {
            /** @var DOMElement $opt */
            $opt = $template->cloneNode(true);
            $input = $xp->query('.//input', $opt)->item(0);
            $lab = $xp->query('.//label', $opt)->item(0);
            $id = $base.'-'.$value;
            if ($input instanceof DOMElement) {
                $input->setAttribute('name', $name);
                $input->setAttribute('value', (string) $value);
                $input->setAttribute('id', $id);
                $input->removeAttribute('required');
                $input->removeAttribute('data-fill-with');
                in_array((int) $value, $selected, true) ? $input->setAttribute('checked', 'checked') : $input->removeAttribute('checked');
            }
            if ($lab instanceof DOMElement) {
                $lab->setAttribute('for', $id);
                $lab->textContent = $label;
            }
            $container->appendChild($opt);
        }
    }
}
