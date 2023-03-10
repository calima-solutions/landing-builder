<?php

namespace Calima\LandingBuilder\Grapesjs;

use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Str;

class ContentParser {
    public function parse(string $content): string
    {
        $content = $this->parseComponents($content);
        $content = $this->parseLivewire($content);

        // remove body tags
        $content = Str::replaceFirst('<body>', '', $content);
        $content = Str::replaceLast('</body>', '', $content);

        return Blade::render($content);
    }

    private function parseComponents(string $content): string
    {
        return $this->_componentShortcodes($content, 'builder', function ($componentName, $variables) {
            $html = '';
            $variables = [
                'component' => $componentName,
                'variables' => ['test' => 1],
            ];
            if (!empty($variables)) {
                $html = "@php";
                foreach ($variables as $name => $value) {
                    $html .= "\n\t\${$name} = " . var_export($value, true) . ";";
                }
                $html .= "\n\t@endphp";
            }
            $html .= "\n\t<x-" . $componentName;
            foreach ($variables as $name => $value) {
                $html .= " :{$name}=\"\${$name}\"";
            }
            $html .= ' />';
            return $html;
        });
    }

    private function parseLivewire(string $content): string
    {
        return $this->_componentShortcodes($content, 'livewire', function ($componentName, $variables) {
            $html = '';
            if (!empty($variables)) {
                $html = "@php";
                foreach ($variables as $name => $value) {
                    $html .= "\n\t\${$name} = " . var_export($value, true) . ";";
                }
                $html .= "\n\t@endphp";
            }
            $html .= "\n\t<livewire:" . $componentName;
            foreach ($variables as $name => $value) {
                $html .= " :{$name}=\"\${$name}\"";
            }
            $html .= ' />';
            return $html;
        });
    }

    private function _componentShortcodes(string $content, string $prefix, $callback): string
    {
        $regex = '/\[(' . $prefix . ':[^\]]+)\]/';
        $content = preg_replace_callback($regex, function ($matches) use ($prefix, $callback) {
            // shortcodes have the following format:
            // builder:component-name :variable1="value" :variable2.each="\App\Models\SomeModel::all()"
            $sharedVariables = [];
            $iterable = [
                'variable' => null,
                'value' => null,
            ];
            $componentName = Str::before(Str::after($matches[1], $prefix . ':'), ' ');
            $variables = html_entity_decode(Str::after($matches[1], ' '));

            $modifiers = [
                'each' => true,
            ];

            $regex = '/(?<variable>[:\w\-._]+)\s*=\s*("?)(?<value>[^"]+)("?)/';
            preg_match_all($regex, $variables, $variableMatches, PREG_SET_ORDER, 0);
            foreach ($variableMatches as $match) {
                $modifiers = collect(explode('.', $match['variable']), 1)
                    ->slice(1)
                    ->filter(fn ($name, $key) => ! isset($modifiers[$key]))
                    ->mapWithKeys(fn ($name) => [$name => true])
                    ->toArray();

                $variableName = $match['variable'];
                foreach ($modifiers as $modifier => $value) {
                    $variableName = Str::replace('.' . $modifier, '', $variableName);
                }

                $variableName = Str::after($variableName, ':');

                if (isset($modifiers['each'])) {
                    $iterable['variable'] = $variableName;
                    $iterable['value'] = eval('return ' . Str::finish($match['value'], ';'));
                } else {
                    if (Str::startsWith($match['variable'], ':')) {
                        $sharedVariables[$variableName] = eval('return ' . Str::finish($match['value'], ';'));
                    } else {
                        $sharedVariables[$variableName] = $match['value'];
                    }
                }
            }

            if (is_null($iterable['value'])) {
                $content = $callback($componentName, $sharedVariables);
            } else {
                $items = $iterable['value'];
                if (! is_iterable($items)) {
                    $items = [$items];
                }

                $content = '';
                foreach ($items as $item) {
                    $content .= $callback($componentName, array_merge([
                        $iterable['variable'] => $item,
                    ], $sharedVariables));
                }
            }
            return $content;
        }, $content);
        return $content;
    }
}
