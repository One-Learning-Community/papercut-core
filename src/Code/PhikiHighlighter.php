<?php

declare(strict_types=1);

namespace Papercut\Code;

use Papercut\Value\Color;
use Phiki\Exceptions\UnrecognisedGrammarException;
use Phiki\Exceptions\UnrecognisedThemeException;
use Phiki\Grammar\Grammar;
use Phiki\Grammar\GrammarParser;
use Phiki\Phiki;

/**
 * Syntax highlighting through the optional phiki/phiki package (TextMate grammars and VS Code themes).
 */
final class PhikiHighlighter implements Highlighter
{
    private readonly Phiki $phiki;

    /** @var array<string, string> grammar name => JSON path, for grammars registered here */
    private array $grammarPaths = [];

    /** @var array<string, string> scope name => grammar name, for grammars registered here */
    private array $grammarScopes = [];

    /** @var array<string, string> alias => grammar name */
    private array $aliases = [];

    /** @var array<string, array{background: Color, foreground: Color}> */
    private array $themeColors = [];

    /** @var array<string, array<mixed>> grammar name => its JSON with injections merged in so far */
    private array $patchedHosts = [];

    private int $injectionCount = 0;

    public static function available(): bool
    {
        return class_exists(Phiki::class);
    }

    public function __construct(?Phiki $phiki = null)
    {
        $this->phiki = $phiki ?? new Phiki();
        $this->theme(CodeTheme::DEFAULT, CodeTheme::defaultPath());
    }

    public function grammar(string $name, string $path): self
    {
        $json = self::readJson($path);
        $name = strtolower($name);
        $this->grammarPaths[$name] = $path;
        if (is_string($json['scopeName'] ?? null)) {
            $this->grammarScopes[$json['scopeName']] = $name;
        }
        $this->phiki->grammar($name, $path);
        unset($this->patchedHosts[$name]);
        if (is_string($json['scopeName'] ?? null)) {
            $this->mapScope($json['scopeName'], $name);
        }

        return $this;
    }

    public function theme(string $name, string $path): self
    {
        $name = strtolower($name);
        $this->themeColors[$name] = CodeTheme::editorColors($path);
        $this->phiki->theme($name, $path);

        return $this;
    }

    public function alias(string $alias, string $language): self
    {
        $alias = strtolower($alias);
        $language = strtolower($language);
        $this->aliases[$alias] = $language;
        $this->phiki->alias($alias, $language);

        return $this;
    }

    /**
     * Register a VS Code injection grammar (injectionSelector + patterns). It is merged into each listed host grammar now,
     * so the hosts must be bundled or registered already. Like Phiki, it applies only when one of the hosts is the block's
     * language, not when a host is embedded in another language's block.
     *
     * @param list<string> $into scope names of the host grammars
     * @throws \InvalidArgumentException when the file is unreadable or invalid, or an `into` scope matches no grammar
     */
    public function injection(string $path, array $into): self
    {
        $inj = self::readJson($path);
        $selector = $inj['injectionSelector'] ?? null;
        $patterns = $inj['patterns'] ?? null;
        if (!is_string($selector) || !is_array($patterns)) {
            throw new \InvalidArgumentException(sprintf('The injection grammar file %s has no "injectionSelector" and "patterns".', $path));
        }
        if ($into === []) {
            throw new \InvalidArgumentException(sprintf('The injection grammar %s needs at least one grammar scope to apply to.', $path));
        }
        $hosts = [];
        foreach ($into as $scope) {
            $host = $this->hostFor($scope);
            if ($host === null) {
                throw new \InvalidArgumentException(sprintf('The injection grammar %s applies to the scope "%s", which matches no bundled or registered grammar (register the host first).', $path, $scope));
            }
            $hosts[$host[0]] = $host[1];
        }

        $repository = is_array($inj['repository'] ?? null) ? $inj['repository'] : [];
        $prefix = sprintf('inj%d_', $this->injectionCount++);
        $keys = array_map('strval', array_keys($repository));
        /** @var array<mixed> $patterns */
        $patterns = self::prefixIncludes($patterns, $prefix, $keys);
        $prefixed = [];
        foreach ($repository as $key => $rule) {
            $prefixed[$prefix . $key] = is_array($rule) ? self::prefixIncludes($rule, $prefix, $keys) : $rule;
        }

        $parser = new GrammarParser();
        $merged = [];
        try {
            foreach ($hosts as $name => $hostPath) {
                $json = $this->patchedHosts[$name] ?? self::readJson($hostPath);
                $existing = is_array($json['injections'] ?? null) ? $json['injections'] : [];
                $current = is_array($existing[$selector]['patterns'] ?? null) ? $existing[$selector]['patterns'] : [];
                $existing[$selector] = ['patterns' => array_merge($current, $patterns)];
                $json['injections'] = $existing;
                $json['repository'] = (is_array($json['repository'] ?? null) ? $json['repository'] : []) + $prefixed;
                $merged[$name] = [$json, $parser->parse($json)];
            }
        } catch (\InvalidArgumentException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new \InvalidArgumentException(sprintf('The injection grammar %s cannot be merged: %s', $path, $e->getMessage()), 0, $e);
        }
        foreach ($merged as $name => [$json, $parsed]) {
            $this->patchedHosts[$name] = $json;
            $this->phiki->grammar($name, $parsed);
        }

        return $this;
    }

    public function highlight(string $code, string $language, string $theme): HighlightedCode
    {
        $original = $language;
        $language = $this->canonical($language);
        $theme = strtolower($theme);
        $colors = $this->editorColors($theme);
        $code = str_replace(["\r\n", "\r"], "\n", $code);
        if ($code === '') {
            // Phiki turns the empty string into one empty line; there is nothing to show.
            $this->tokenise('', $language, $theme, $original);
            return new HighlightedCode([], $colors['background'], $colors['foreground']);
        }

        $tokenLines = $this->tokenise($code, $language, $theme, $original);

        $lines = [];
        foreach ($tokenLines as $tokenLine) {
            $line = [];
            foreach ($tokenLine as $highlighted) {
                $text = rtrim($highlighted->token->text, "\n");
                if ($text === '') {
                    continue;
                }
                $settings = $highlighted->settings;
                $first = reset($settings);
                $line[] = $this->token($text, $first === false ? null : $first);
            }
            $lines[] = $line;
        }

        return new HighlightedCode($lines, $colors['background'], $colors['foreground']);
    }

    /** @return iterable<array<\Phiki\Token\HighlightedToken>> */
    private function tokenise(string $code, string $language, string $theme, string $original): iterable
    {
        try {
            return $this->phiki->codeToHighlightedTokens($code, $language, $theme);
        } catch (UnrecognisedGrammarException $e) {
            throw new UnknownLanguage(sprintf('Unknown code language "%s".', $original), 0, $e);
        } catch (UnrecognisedThemeException $e) {
            throw new UnknownTheme(sprintf('Unknown code theme "%s".', $theme), 0, $e);
        }
    }

    public function displayName(string $language): ?string
    {
        $name = $this->canonical($language);
        $path = $this->grammarPaths[$name] ?? null;
        if ($path === null) {
            $bundled = self::bundled($name);
            $path = $bundled?->path();
        }
        if ($path !== null) {
            $json = self::readJson($path);
            foreach (['displayName', 'name'] as $key) {
                if (is_string($json[$key] ?? null) && $json[$key] !== '') {
                    return $json[$key];
                }
            }

            return null;
        }
        try {
            return $this->phiki->environment()->grammars->has($name) ? $this->phiki->environment()->grammars->get($name)->name : null;
        } catch (UnrecognisedGrammarException) {
            return null;
        }
    }

    private function canonical(string $language): string
    {
        $name = strtolower($language);

        return $this->aliases[$name] ?? $name;
    }

    private static function bundled(string $name): ?Grammar
    {
        $grammar = Grammar::tryFrom($name);
        if ($grammar !== null) {
            return $grammar;
        }
        foreach (Grammar::cases() as $case) {
            if (in_array($name, $case->aliases(), true)) {
                return $case;
            }
        }

        return null;
    }

    /** @return array{background: Color, foreground: Color} */
    private function editorColors(string $theme): array
    {
        $theme = strtolower($theme);
        if (isset($this->themeColors[$theme])) {
            return $this->themeColors[$theme];
        }
        try {
            $parsed = $this->phiki->environment()->themes->get($theme);
        } catch (UnrecognisedThemeException $e) {
            throw new UnknownTheme(sprintf('Unknown code theme "%s".', $theme), 0, $e);
        }
        $default = $this->themeColors[CodeTheme::DEFAULT];

        return $this->themeColors[$theme] = [
            'background' => self::color($parsed->colors['editor.background'] ?? null) ?? $default['background'],
            'foreground' => self::color($parsed->colors['editor.foreground'] ?? null) ?? $default['foreground'],
        ];
    }

    private function token(string $text, mixed $settings): CodeToken
    {
        if (!$settings instanceof \Phiki\Theme\TokenSettings) {
            return new CodeToken($text);
        }
        $words = preg_split('/\s+/', strtolower((string) $settings->fontStyle), -1, PREG_SPLIT_NO_EMPTY);
        $words = $words === false ? [] : $words;

        return new CodeToken(
            $text,
            self::color($settings->foreground),
            in_array('bold', $words, true),
            in_array('italic', $words, true),
            in_array('underline', $words, true),
        );
    }

    private static function color(mixed $value): ?Color
    {
        if (!is_string($value) || $value === '') {
            return null;
        }
        try {
            return Color::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }

    /** @return array<mixed> */
    private static function readJson(string $path): array
    {
        $raw = is_file($path) ? @file_get_contents($path) : false;
        if ($raw === false) {
            throw new \InvalidArgumentException(sprintf('Cannot read the file %s.', $path));
        }
        $json = json_decode($raw, true);
        if (!is_array($json)) {
            throw new \InvalidArgumentException(sprintf('The file %s is not valid JSON.', $path));
        }

        return $json;
    }

    /** Works around Phiki not mapping a registered grammar's scope name, so it cannot be embedded by scope. */
    private function mapScope(string $scope, string $name): void
    {
        $repository = $this->phiki->environment()->grammars;
        \Closure::bind(static function (object $repo) use ($scope, $name): void {
            if (property_exists($repo, 'scopesToGrammar')) {
                $repo->scopesToGrammar[$scope] = $name;
            }
        }, null, $repository::class)($repository);
    }

    /** @return array{string, string}|null grammar name and JSON path */
    private function hostFor(string $scope): ?array
    {
        $name = $this->grammarScopes[$scope] ?? null;
        if ($name !== null) {
            return [$name, $this->grammarPaths[$name]];
        }
        foreach (Grammar::cases() as $case) {
            if ($case->scopeName() === $scope) {
                return [$case->value, $case->path()];
            }
        }

        return null;
    }

    /**
     * @param array<mixed> $rules
     * @param list<string> $keys repository keys that were renamed
     * @return array<mixed>
     */
    private static function prefixIncludes(array $rules, string $prefix, array $keys): array
    {
        foreach ($rules as $k => $value) {
            if ($k === 'include' && is_string($value) && str_starts_with($value, '#') && in_array(substr($value, 1), $keys, true)) {
                $rules[$k] = '#' . $prefix . substr($value, 1);
            } elseif (is_array($value)) {
                $rules[$k] = self::prefixIncludes($value, $prefix, $keys);
            }
        }

        return $rules;
    }
}
