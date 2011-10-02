<?php defined('SYSPATH') or die('No direct script access.');
/**
 * Minify CSS
 *
 * This class uses Minify_CSS_Compressor and Minify_CSS_UriRewriter to 
 * minify CSS and rewrite relative URIs.
 * 
 * @package Minify
 * @author Stephen Clay <steve@mrclay.org>
 * @author http://code.google.com/u/1stvamp/ (Issue 64 patch)
 */
class Minify_CSS {
    /**
     * Minify a CSS string
     * 
     * @param string $css
     * 
     * @param array $options available options:
     * 
     * 'preserveComments': (default true) multi-line comments that begin
     * with "/*!" will be preserved with newlines before and after to
     * enhance readability.
     * 
     * 'prepend_relative_path': (default null) if given, this string will be
     * prepended to all relative URIs in import/url declarations
     * 
     * 'current_dir': (default null) if given, this is assumed to be the
     * directory of the current CSS file. Using this, minify will rewrite
     * all relative URIs in import/url declarations to correctly point to
     * the desired files. For this to work, the files *must* exist and be
     * visible by the PHP process.
     *
     * 'symlinks': (default = array()) If the CSS file is stored in 
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
    public static function minify($css, $options = array()) 
    {
        if (isset($options['preserve_comments']) 
            && !$options['preserve_comments'])
		{
            $css = Minify_CSS_Compres::compres($css, $options);
        }
		else
		{
            $css = Minify_CSS_Comment_Preserver::process(
                $css
                ,array('Minify_CSS_Compres', 'compres')
                ,array($options)
            );
        }
        if (! isset($options['current_dir']) && ! isset($options['prepend_relative_path']))
		{
            return $css;
        }
        if (isset($options['current_dir']))
		{
            return Minify_CSS_UriRewriter::rewrite(
                $css
                ,$options['current_dir']
                ,isset($options['doc_root']) ? $options['doc_root'] : $_SERVER['DOCUMENT_ROOT']
                ,isset($options['symlinks']) ? $options['symlinks'] : array()
            );  
        }
		else
		{
            return Minify_CSS_UriRewriter::prepend(
                $css
                ,$options['prepend_relative_path']
            );
        }
    }
}
