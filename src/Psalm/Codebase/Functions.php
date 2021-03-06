<?php
namespace Psalm\Codebase;

use Psalm\Checker\ProjectChecker;
use Psalm\Checker\StatementsChecker;
use Psalm\Provider\FileStorageProvider;
use Psalm\StatementsSource;
use Psalm\Storage\FunctionLikeStorage;

class Functions
{
    /**
     * @var FileStorageProvider
     */
    private $file_storage_provider;

    /**
     * @var array<string, FunctionLikeStorage>
     */
    private static $stubbed_functions;

    /**
     * @var Reflection
     */
    private $reflection;

    public function __construct(FileStorageProvider $storage_provider, Reflection $reflection)
    {
        $this->file_storage_provider = $storage_provider;
        $this->reflection = $reflection;

        self::$stubbed_functions = [];
    }

    /**
     * @param  StatementsChecker|null $statements_checker
     * @param  string $function_id
     *
     * @return FunctionLikeStorage
     */
    public function getStorage($statements_checker, $function_id)
    {
        if (isset(self::$stubbed_functions[strtolower($function_id)])) {
            return self::$stubbed_functions[strtolower($function_id)];
        }

        if ($this->reflection->hasFunction($function_id)) {
            return $this->reflection->getFunctionStorage($function_id);
        }

        if (!$statements_checker) {
            throw new \UnexpectedValueException('$statements_checker must not be null here');
        }

        $file_path = $statements_checker->getFilePath();
        $file_storage = $this->file_storage_provider->get($file_path);

        $function_checkers = $statements_checker->getFunctionCheckers();

        if (isset($function_checkers[$function_id])) {
            $function_id = $function_checkers[$function_id]->getMethodId();

            if (!isset($file_storage->functions[$function_id])) {
                throw new \UnexpectedValueException(
                    'Expecting ' . $function_id . ' to have storage in ' . $file_path
                );
            }

            return $file_storage->functions[$function_id];
        }

        // closures can be returned here
        if (isset($file_storage->functions[$function_id])) {
            return $file_storage->functions[$function_id];
        }

        if (!isset($file_storage->declaring_function_ids[$function_id])) {
            throw new \UnexpectedValueException(
                'Expecting ' . $function_id . ' to have storage in ' . $file_path
            );
        }

        $declaring_file_path = $file_storage->declaring_function_ids[$function_id];

        $declaring_file_storage = $this->file_storage_provider->get($declaring_file_path);

        if (!isset($declaring_file_storage->functions[$function_id])) {
            throw new \UnexpectedValueException(
                'Not expecting ' . $function_id . ' to not have storage in ' . $declaring_file_path
            );
        }

        return $declaring_file_storage->functions[$function_id];
    }

    /**
     * @param string $function_id
     * @param FunctionLikeStorage $storage
     *
     * @return void
     */
    public function addStubbedFunction($function_id, FunctionLikeStorage $storage)
    {
        self::$stubbed_functions[strtolower($function_id)] = $storage;
    }

    /**
     * @param  string  $function_id
     *
     * @return bool
     */
    public function hasStubbedFunction($function_id)
    {
        return isset(self::$stubbed_functions[strtolower($function_id)]);
    }

    /**
     * @param  string $function_id
     *
     * @return bool
     */
    public function functionExists(StatementsChecker $statements_checker, $function_id)
    {
        $file_storage = $this->file_storage_provider->get($statements_checker->getFilePath());

        if (isset($file_storage->declaring_function_ids[$function_id])) {
            return true;
        }

        if ($this->reflection->hasFunction($function_id)) {
            return true;
        }

        if (isset(self::$stubbed_functions[strtolower($function_id)])) {
            return true;
        }

        if (isset($statements_checker->getFunctionCheckers()[$function_id])) {
            return true;
        }

        if ($this->reflection->registerFunction($function_id) === false) {
            return false;
        }

        return true;
    }

    /**
     * @param  string                   $function_name
     * @param  StatementsSource         $source
     *
     * @return string
     */
    public function getFullyQualifiedFunctionNameFromString($function_name, StatementsSource $source)
    {
        if (empty($function_name)) {
            throw new \InvalidArgumentException('$function_name cannot be empty');
        }

        if ($function_name[0] === '\\') {
            return substr($function_name, 1);
        }

        $function_name_lcase = strtolower($function_name);

        $aliases = $source->getAliases();

        $imported_function_namespaces = $aliases->functions;
        $imported_namespaces = $aliases->uses;

        if (strpos($function_name, '\\') !== false) {
            $function_name_parts = explode('\\', $function_name);
            $first_namespace = array_shift($function_name_parts);
            $first_namespace_lcase = strtolower($first_namespace);

            if (isset($imported_namespaces[$first_namespace_lcase])) {
                return $imported_namespaces[$first_namespace_lcase] . '\\' . implode('\\', $function_name_parts);
            }

            if (isset($imported_function_namespaces[$first_namespace_lcase])) {
                return $imported_function_namespaces[$first_namespace_lcase] . '\\' .
                    implode('\\', $function_name_parts);
            }
        } elseif (isset($imported_namespaces[$function_name_lcase])) {
            return $imported_namespaces[$function_name_lcase];
        } elseif (isset($imported_function_namespaces[$function_name_lcase])) {
            return $imported_function_namespaces[$function_name_lcase];
        }

        $namespace = $source->getNamespace();

        return ($namespace ? $namespace . '\\' : '') . $function_name;
    }

    /**
     * @param  string $function_id
     * @param  string $file_path
     *
     * @return bool
     */
    public static function isVariadic(ProjectChecker $project_checker, $function_id, $file_path)
    {
        $file_storage = $project_checker->file_storage_provider->get($file_path);

        return isset($file_storage->functions[$function_id]) && $file_storage->functions[$function_id]->variadic;
    }
}
