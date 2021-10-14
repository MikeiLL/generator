<?php

/**
 * The functions used to run the generator
 */
 
use LightnCandy\LightnCandy;
use LightnCandy\Runtime;
/**
 * Generate a new wpbp.json in the folder
 *
 * @global object $cmd
 * @global object $clio
 * @global object $error
 * @global object $info
 */
function create_wpbp_json() {
    global $cmd, $clio, $error, $info;

    if ( $cmd[ 'json' ] ) {
        if ( !copy( dirname( __FILE__ ) . '/wpbp.json', getcwd() . '/wpbp.json' ) ) {
            $clio->display( "Failed to copy wpbp.json...\n" )->style( $error );
            return;
        }
        
        $clio->display( "😀 wpbp.json generated\n" )->style( $info );
        exit();
        
        return;
    }
    
    if ( !file_exists( getcwd() . '/wpbp.json' ) ) {
        $clio->display( "😡 wpbp.json file missing..." )->style( $error )->newLine();
        $clio->display( "😉 Generate it with: wpbp-generator --json" )->style( $error )->newLine();
        $clio->display( "Forget a hipster Q&A procedure and fill that JSON with your custom configuration!" )->style( $error )->newLine();
        $clio->display( "  Let's do your changes and execute the script again! Use the --dev parameter to use the development version of the boilerplate!" )->style( $error );
        $clio->newLine();
        $clio->display( "Help: wpbp-generator --help 😉" )->style( $info )->newLine();
        exit();
    }
}

/**
 * Download the boilerplate based from theversion asked
 *
 * @global object $cmd
 * @global object $clio
 * @global object $info
 */
function download_wpbp() {
    global $cmd, $clio, $info, $error;
    $version = WPBP_VERSION;

    if ( $cmd[ 'dev' ] ) {
        $version = 'master';
    }

    $clio->display( "😎 Downloading " . $version . " package" )->style( $info )->newLine();

    $download = @file_get_contents( 'http://github.com/WPBP/WordPress-Plugin-Boilerplate-Powered/archive/' . $version . '.zip' );
    if ( $download === false ) {
        $clio->display( "😡 The " . $version . " version is not yet avalaible! Use the --dev parameter!" )->style( $error )->newLine();
        die();
    }

    file_put_contents( 'plugin.zip', $download );

    extract_wpbp();
}

/**
 * Extract the boilerplate
 *
 * @global object $cmd
 * @global object $clio
 * @global object $info
 * @global object $error
 */
function extract_wpbp() {
    global $cmd, $clio, $info, $error;
    if ( ! plugin_temp_exist() ) {
        if ( file_exists( getcwd() . '/plugin.zip' ) ) {
            if ( file_exists( getcwd() . DIRECTORY_SEPARATOR . WPBP_PLUGIN_SLUG ) ) {
                $clio->display( "Folder " . WPBP_PLUGIN_SLUG . " already exist!" )->style( $error )->newLine();
                exit();
            }

            $clio->display( "Extract Boilerplate" )->style( $info )->newLine();
            try {
                $zip = new ZipArchive;
            } catch (Exception $e) {
                echo 'Caught exception: ',  $e->getMessage(), "\n";
                exit();
            }
            $res = $zip->open( getcwd() . '/plugin.zip' );
            if ( $res === true ) {
                $zip->extractTo( getcwd() . '/plugin_temp/' );
                $zip->close();
                $version = WPBP_VERSION;

                if ( $cmd[ 'dev' ] ) {
                    $version = 'master';
                }

                try {
                    rename( getcwd() . '/plugin_temp/WordPress-Plugin-Boilerplate-Powered-' . $version . '/plugin-name/', getcwd() . DIRECTORY_SEPARATOR . WPBP_PLUGIN_SLUG );
                    rename( getcwd() . '/plugin_temp/WordPress-Plugin-Boilerplate-Powered-' . $version . '/.gitignore', getcwd() . DIRECTORY_SEPARATOR . WPBP_PLUGIN_SLUG . '/.gitignore' );
                    remove_file_folder( getcwd() . '/plugin_temp/' );
                    if ( !$cmd[ 'dev' ] ) {
                        remove_file_folder( getcwd() . '/plugin.zip' );
                    }
                } catch ( Exception $e ) {
                    $clio->display( $e )->style( $error );
                }

                $clio->display( "Boilerplate Extracted" )->style( $info )->newLine();
            }

            return;
        }

        // If the package not exist download it
        download_wpbp();
    }
}

/**
 * Execute Lightncandy on the boilerplate files
 *
 * @global object $cmd
 * @global object $clio
 * @global object $info
 * @param  array $config The config of the request.
 */
function execute_generator( $config ) {
    global $clio, $info;
    $files = get_files();
    foreach ( $files as $file ) {
        $file_content = file_get_contents( $file );
        $new_file_content = replace_name_slug( $config, $file_content );
        $new_file_content = parse_conditional_template( $file, $config, $new_file_content );

        if ( strpos( $file, '.gitignore' ) ) {
            $new_file_content = str_replace( 'plugin-name/', '', $new_file_content );
        }

        if ( $new_file_content !== $file_content ) {
            file_put_contents( $file, $new_file_content );
        }
    }

    $clio->display( "Generation done, I am superfast! You: (ʘ_ʘ)\n" )->style( $info )->newLine();
    git_init();
    grunt();
    grumphp();
}

function parse_conditional_template( $file, $config, $file_content ) {
    global $cmd;
    if ( $cmd[ 'dev' ] ) {
        print_v( 'Parsing ' . $file );
        $lc         = LightnCandy::compile(
                $file_content,
                array(
                    'flags' => LightnCandy::FLAG_ERROR_LOG | LightnCandy::FLAG_ERROR_EXCEPTION | LightnCandy::FLAG_RENDER_DEBUG,
                )
        );
        $lc_prepare = LightnCandy::prepare( $lc );
        $file_content = $lc_prepare( $config, array( 'debug' => Runtime::DEBUG_ERROR_EXCEPTION | Runtime::DEBUG_ERROR_LOG ) );

        return $file_content;
    }
    
    $lc         = LightnCandy::compile( $file_content );
    $lc_prepare = LightnCandy::prepare( $lc );
    $file_content    = $lc_prepare( $config );
    
    return $file_content;
}

/**
 * Load user wpbp.json and add the terms missing as false
 *
 * @global object $clio
 * @global object $error
 * @return array
 */
function parse_config() {
    global $clio, $error;
    $config = json_decode( file_get_contents( getcwd() . '/wpbp.json' ), true );
    // Detect a misleading json file
    if ( json_last_error() !== JSON_ERROR_NONE ) {
        $clio->display( "😡 Your JSON is broken!" )->style( $error )->newLine();
        exit;
    }

    $config         = array_to_var( $config );
    $config_default = array_to_var( json_decode( file_get_contents( dirname( __FILE__ ) . '/wpbp.json' ), true ), true );
    foreach ( $config_default as $key => $value ) {
        if ( !isset( $config[ $key ] ) ) {
            $config[ $key ] = '';
        }
    }

    return $config;
}
