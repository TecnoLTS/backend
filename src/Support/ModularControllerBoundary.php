<?php

declare(strict_types=1);

namespace App\Support;

use ReflectionClass;

final class ModularControllerBoundary
{
    private const LEGACY_CONTROLLER_PREFIX = 'App\\Controllers\\';

    /** @return list<string> */
    public static function sourceViolations(string $source): array
    {
        if (str_contains($source, 'App\\Controllers')) {
            return ['referencia el namespace legacy App\\Controllers'];
        }

        return [];
    }

    /** @return list<string> */
    public static function httpHandlerListViolations(string $methodSource): array
    {
        $calls = self::methodCallNames($methodSource);
        $violations = [];
        foreach ([
            'getAll' => 'invoca getAll() desde un handler HTTP; use una consulta paginada y acotada',
            'getByUserId' => 'invoca getByUserId() sin pagina desde un handler HTTP; use getByUserIdPage()',
            'listApprovedForProduct' => 'invoca listApprovedForProduct() sin pagina; use approvedPageForProduct()',
        ] as $method => $message) {
            if (in_array($method, $calls, true)) {
                $violations[] = $message;
            }
        }

        return $violations;
    }

    /**
     * Extrae invocaciones reales del flujo PHP. Comentarios, docblocks y
     * literales que contienen texto como "->getAll(" no producen falsos
     * positivos, a diferencia de una busqueda de substrings.
     *
     * @return list<string>
     */
    public static function methodCallNames(string $methodSource): array
    {
        $tokens = token_get_all(str_contains($methodSource, '<?php') ? $methodSource : "<?php\n" . $methodSource);
        $calls = [];
        $count = count($tokens);
        for ($index = 0; $index < $count; $index++) {
            $token = $tokens[$index];
            $isOperator = is_array($token)
                && in_array($token[0], array_filter([
                    T_OBJECT_OPERATOR,
                    defined('T_NULLSAFE_OBJECT_OPERATOR') ? T_NULLSAFE_OBJECT_OPERATOR : null,
                    T_DOUBLE_COLON,
                ]), true);
            if (!$isOperator) {
                continue;
            }

            $nameIndex = self::nextMeaningfulTokenIndex($tokens, $index + 1);
            $openIndex = $nameIndex === null ? null : self::nextMeaningfulTokenIndex($tokens, $nameIndex + 1);
            if ($nameIndex === null || $openIndex === null) {
                continue;
            }
            $nameToken = $tokens[$nameIndex];
            $openToken = $tokens[$openIndex];
            if (!is_array($nameToken) || $nameToken[0] !== T_STRING || $openToken !== '(') {
                continue;
            }
            $calls[$nameToken[1]] = true;
        }

        return array_keys($calls);
    }

    /** @return list<string> */
    public static function reflectionViolations(ReflectionClass $controller): array
    {
        $violations = [];

        for ($parent = $controller->getParentClass(); $parent !== false; $parent = $parent->getParentClass()) {
            if (str_starts_with($parent->getName(), self::LEGACY_CONTROLLER_PREFIX)) {
                $violations[] = sprintf('hereda directa o indirectamente de %s', $parent->getName());
            }
        }

        foreach (self::allTraitNames($controller) as $traitName) {
            if (str_starts_with($traitName, self::LEGACY_CONTROLLER_PREFIX)) {
                $violations[] = sprintf('usa el trait legacy %s', $traitName);
            }
        }

        foreach (array_keys($controller->getInterfaces()) as $interfaceName) {
            if (str_starts_with($interfaceName, self::LEGACY_CONTROLLER_PREFIX)) {
                $violations[] = sprintf('implementa la interfaz legacy %s', $interfaceName);
            }
        }

        return array_values(array_unique($violations));
    }

    /** @return list<string> */
    private static function allTraitNames(ReflectionClass $class): array
    {
        $traits = [];
        for ($current = $class; $current !== false; $current = $current->getParentClass()) {
            foreach ($current->getTraits() as $trait) {
                $traits[$trait->getName()] = true;
                foreach (self::allTraitNames($trait) as $nestedTrait) {
                    $traits[$nestedTrait] = true;
                }
            }
        }

        return array_keys($traits);
    }

    /** @param array<int,array|string> $tokens */
    private static function nextMeaningfulTokenIndex(array $tokens, int $offset): ?int
    {
        $count = count($tokens);
        for ($index = $offset; $index < $count; $index++) {
            $token = $tokens[$index];
            if (is_array($token) && in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            return $index;
        }
        return null;
    }
}
