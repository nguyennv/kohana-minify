<?php defined('SYSPATH') or die('No direct script access.');
/**
 * Class Minify_CSS_UriRewriter  
 * @package Minify
 */

/**
 * Rewrite file-relative URIs as root-relative in CSS files
 *
 * @package Minify
 * @author Stephen Clay <steve@mrclay.org>
 */
class Minify_CSS_UriRewriter {
    
    /**
     * Defines which class to call as part of callbacks, change this
     * if you extend Minify_CSS_UriRewriter
     * @var string
     */
    protected static $class_name = 'Minify_CSS_UriRewriter';
    
    /**
     * rewrite() and rewrite_relative() append debugging information here
     * @var string
     */
    public static $debug_text = '';
    
    /**
     * Rewrite file relative URIs as root relative in CSS files
     * 
     * @param string $css
     * 
     * @param string $current_dir The directory of the current CSS file.
     * 
     * @param string $doc_root The document root of the web site in which 
     * the CSS file resides (default = $_SERVER['DOCUMENT_ROOT']).
     * 
     * @param array $symlinks (default = array()) If the CSS file is stored in 
     * a symlink-ed directory, provide an array of link paths to
     * target paths, where the link paths are within the document root. Because 
     * paths need to be normalized for this to work, use "//" to substitute 
     * the doc root in the link paths (the array keys). E.g.:
     * <code>
     * array('//symlink' => '/real/target/path') // unix
     * array('//static' => 'D:\\staticStorage')  // Windows
     * </code>
     * 
     * @return string
     */
    public static function rewrite($css, $current_dir, $doc_root = null, $symlinks = array()) 
    {
        self::$_doc_root = self::_realpath(
            $doc_root ? $doc_root : $_SERVER['DOCUMENT_ROOT']
        );
        self::$_current_dir = self::_realpath($current_dir);
        self::$_symlinks = array();
        
        // normalize symlinks
        foreach ($symlinks as $link => $target) {
            $link = ($link === '//')
                ? self::$_doc_root
                : str_replace('//', self::$_doc_root . '/', $link);
            $link = strtr($link, '/', DIRECTORY_SEPARATOR);
            self::$_symlinks[$link] = self::_realpath($target);
        }

        self::$debug_text .= "doc_root    : " . self::$_doc_root . "\n"
                          . "current_dir : " . self::$_current_dir . "\n";
        if (self::$_symlinks) {
            self::$debug_text .= "symlinks : " . var_export(self::$_symlinks, 1) . "\n";
        }
        self::$debug_text .= "\n";
        
        $css = self::_trim_urls($css);
        
        // rewrite
        $css = preg_replace_callback('/@import\\s+([\'"])(.*?)[\'"]/'
            ,array(self::$class_name, '_process_uri_cb'), $css);
        $css = preg_replace_callback('/url\\(\\s*([^\\)\\s]+)\\s*\\)/'
            ,array(self::$class_name, '_process_uri_cb'), $css);
        $css = preg_replace_callback('/src=\'\\s*([^\\)\\s]+)\\s*\'/'
            ,array(self::$class_name, '_process_src_cb'), $css);

        return $css;
    }
    
    /**
     * Prepend a path to relative URIs in CSS files
     * 
     * @param string $css
     * 
     * @param string $path The path to prepend.
     * 
     * @return string
     */
    public static function prepend($css, $path)
    {
        self::$_prepend_path = $path;
        
        $css = self::_trim_urls($css);
        
        // append
        $css = preg_replace_callback('/@import\\s+([\'"])(.*?)[\'"]/'
            ,array(self::$class_name, '_process_uri_cb'), $css);
        $css = preg_replace_callback('/url\\(\\s*([^\\)\\s]+)\\s*\\)/'
            ,array(self::$class_name, '_process_uri_cb'), $css);
        $css = preg_replace_callback('/src=\'\\s*([^\\)\\s]+)\\s*\'/'
            ,array(self::$class_name, '_process_src_cb'), $css);

        self::$_prepend_path = null;
        return $css;
    }
    
    
    /**
     * @var string directory of this stylesheet
     */
    private static $_current_dir = '';
    
    /**
     * @var string DOC_ROOT
     */
    private static $_doc_root = '';
    
    /**
     * @var array directory replacements to map symlink targets back to their
     * source (within the document root) E.g. '/var/www/symlink' => '/var/realpath'
     */
    private static $_symlinks = array();
    
    /**
     * @var string path to prepend
     */
    private static $_prepend_path = null;
    
    private static function _trim_urls($css)
    {
        return preg_replace('/
            url\\(      # url(
            \\s*
            ([^\\)]+?)  # 1 = URI (assuming does not contain ")")
            \\s*
            \\)         # )
        /x', 'url($1)', $css);
    }
    
    private static function _process_uri_cb($m)
    {
        // $m matched either '/@import\\s+([\'"])(.*?)[\'"]/' or '/url\\(\\s*([^\\)\\s]+)\\s*\\)/'
        $is_import = ($m[0][0] === '@');
        // determine URI and the quote character (if any)
        if ($is_import)
		{
            $quote_char = $m[1];
            $uri = $m[2];
        }
		else
		{
            // $m[1] is either quoted or not
            $quote_char = ($m[1][0] === "'" || $m[1][0] === '"')
                ? $m[1][0]
                : '';
            $uri = ($quote_char === '')
                ? $m[1]
                : substr($m[1], 1, strlen($m[1]) - 2);
        }
        // analyze URI
        if ('/' !== $uri[0]                  // root-relative
            && false === strpos($uri, '//')  // protocol (non-data)
            && 0 !== strpos($uri, 'data:')   // data protocol
        )
		{
            // URI is file-relative: rewrite depending on options
            $uri = (self::$_prepend_path !== null)
                ? (self::$_prepend_path . $uri)
                : self::rewrite_relative($uri, self::$_current_dir, self::$_doc_root, self::$_symlinks);
        }
        return $is_import
            ? "@import {$quote_char}{$uri}{$quote_char}"
            : "url({$quote_char}{$uri}{$quote_char})";
    }

    private static function _process_src_cb($m)
    {
		// $m[1] is either quoted or not
		$quote_char = ($m[1][0] === "'" || $m[1][0] === '"')
			? $m[1][0]
			: '';
		$uri = ($quote_char === '')
			? $m[1]
			: substr($m[1], 1, strlen($m[1]) - 2);
        // analyze URI
        if ('/' !== $uri[0]                  // root-relative
            && false === strpos($uri, '//')  // protocol (non-data)
            && 0 !== strpos($uri, 'data:')   // data protocol
        )
		{
            // URI is file-relative: rewrite depending on options
            $uri = (self::$_prepend_path !== null)
                ? (self::$_prepend_path . $uri)
                : self::rewrite_relative($uri, self::$_current_dir, self::$_doc_root, self::$_symlinks);
        }
        return "src='{$quote_char}{$uri}{$quote_char}'";
    }
    
    /**
     * Rewrite a file relative URI as root relative
     *
     * <code>
     * Minify_CSS_UriRewriter::rewrite_relative(
     *       '../img/hello.gif'
     *     , '/home/user/www/css'  // path of CSS file
     *     , '/home/user/www'      // doc root
     * );
     * // returns '/img/hello.gif'
     * 
     * // example where static files are stored in a symlinked directory
     * Minify_CSS_UriRewriter::rewrite_relative(
     *       'hello.gif'
     *     , '/var/staticFiles/theme'
     *     , '/home/user/www'
     *     , array('/home/user/www/static' => '/var/staticFiles')
     * );
     * // returns '/static/theme/hello.gif'
     * </code>
     * 
     * @param string $uri file relative URI
     * 
     * @param string $real_current_dir realpath of the current file's directory.
     * 
     * @param string $real_doc_root realpath of the site document root.
     * 
     * @param array $symlinks (default = array()) If the file is stored in 
     * a symlink-ed directory, provide an array of link paths to
     * real target paths, where the link paths "appear" to be within the document 
     * root. E.g.:
     * <code>
     * array('/home/foo/www/not/real/path' => '/real/target/path') // unix
     * array('C:\\htdocs\\not\\real' => 'D:\\real\\target\\path')  // Windows
     * </code>
     * 
     * @return string
     */
    public static function rewrite_relative($uri, $real_current_dir, $real_doc_root, $symlinks = array())
    {
        // prepend path with current dir separator (OS-independent)
        $path = strtr($real_current_dir, '/', DIRECTORY_SEPARATOR)  
            . DIRECTORY_SEPARATOR . strtr($uri, '/', DIRECTORY_SEPARATOR);
        
        self::$debug_text .= "file-relative URI  : {$uri}\n"
                          . "path prepended     : {$path}\n";
        
        // "unresolve" a symlink back to doc root
        foreach ($symlinks as $link => $target)
		{
            if (0 === strpos($path, $target))
			{
                // replace $target with $link
                $path = $link . substr($path, strlen($target));
                
                self::$debug_text .= "symlink unresolved : {$path}\n";
                
                break;
            }
        }
        // strip doc root
        $path = substr($path, strlen($real_doc_root));
        
        self::$debug_text .= "docroot stripped   : {$path}\n";
        
        // fix to root-relative URI

        $uri = strtr($path, '/\\', '//');

        // remove /./ and /../ where possible
        $uri = str_replace('/./', '/', $uri);
        // inspired by patch from Oleg Cherniy
        do
		{
            $uri = preg_replace('@/[^/]+/\\.\\./@', '/', $uri, 1, $changed);
        } while ($changed);
      
        self::$debug_text .= "traversals removed : {$uri}\n\n";
        
        return $uri;
    }
    
    /**
     * Get realpath with any trailing slash removed. If realpath() fails,
     * just remove the trailing slash.
     * 
     * @param string $path
     * 
     * @return mixed path with no trailing slash
     */
    protected static function _realpath($path)
    {
        $real_path = realpath($path);
        if ($real_path !== false)
		{
            $path = $real_path;
        }
        return rtrim($path, '/\\');
    }
}
