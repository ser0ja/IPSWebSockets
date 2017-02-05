<?

//namespace MyApp\core;

class AutoloaderTLS
{

    private $namespace;

    public function __construct($namespace = null)
    {
        $this->namespace = $namespace;
    }

    public function register()
    {
        spl_autoload_register(array($this, 'loadClass'));
    }

    public function loadClass($className)
    {

/*        if ($this->namespace !== null)
        {
            $className = str_replace($this->namespace . '\\', '', $className);
        }

        $className = __DIR__. $this->namespace.str_replace('\\', DIRECTORY_SEPARATOR, $className);
*/
        $file = __DIR__.DIRECTORY_SEPARATOR.$className . '.php';
        //echo $file.PHP_EOL;        
        if (file_exists($file))
        {
            require_once $file;
        }
    }

}
