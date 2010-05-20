<?php
/**
 * Plugin Name: Windows Azure Storage for WordPress
 * 
 * Plugin URI: http://www.wordpress.org/extend/plugins/windows-azure-storage/
 * 
 * Description: This WordPress plugin allows you to use Windows Azure Storage Service to host your media for your WordPress powered blog.
 * 
 * Version: 1.0
 * 
 * Author: Microsoft
 * 
 * Author URI: http://www.microsoft.com/
 * 
 * License: New BSD License (BSD)
 * 
 * Copyright (c) 2010, Microsoft Corporation. All Rights Reserved.
 * Redistribution and use in source and binary forms, with or without modification, 
 * are permitted provided that the following conditions are met:
 * 
 * Redistributions of source code must retain the above copyright notice, this 
 * list of conditions and the following disclaimer.
 * 
 * Redistributions in binary form must reproduce the above copyright notice, this 
 * list of conditions and the following disclaimer in the documentation and/or 
 * other materials provided with the distribution.
 * 
 * Neither the name of Persistent Systems Ltd. nor the names of its contributors 
 * may be used to endorse or promote products derived from this software without 
 * specific prior written permission.
 * 
 * THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS "AS IS" AND 
 * ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT LIMITED TO, THE IMPLIED 
 * WARRANTIES OF MERCHANTABILITY AND FITNESS FOR A PARTICULAR PURPOSE ARE 
 * DISCLAIMED. IN NO EVENT SHALL THE COPYRIGHT OWNER OR CONTRIBUTORS BE LIABLE FOR 
 * ANY DIRECT, INDIRECT, INCIDENTAL, SPECIAL, EXEMPLARY, OR CONSEQUENTIAL DAMAGES 
 * (INCLUDING, BUT NOT LIMITED TO, PROCUREMENT OF SUBSTITUTE GOODS OR SERVICES; LOSS 
 * OF USE, DATA, OR PROFITS; OR BUSINESS INTERRUPTION) HOWEVER CAUSED AND ON ANY 
 * THEORY OF LIABILITY, WHETHER IN CONTRACT, STRICT LIABILITY, OR TORT (INCLUDING 
 * NEGLIGENCE OR OTHERWISE) ARISING IN ANY WAY OUT OF THE USE OF THIS SOFTWARE, 
 * EVEN IF ADVISED OF THE POSSIBILITY OF SUCH DAMAGE. 
 * 
 * PHP Version 5
 * 
 * @category  WordPress_Plugin
 * @package   Windows_Azure_Storage_For_WordPress
 * @author    Satish Nikam <v-sanika@microsoft.com>
 * @copyright 2010 Copyright � Microsoft Corporation. All Rights Reserved
 * @license   New BSD License (BSD)
 * @link      http://www.microsoft.com
 */

/**
 * This set_include_path statement is needed if you want to deploy wordpress 
 * plugin on Windows Azure. This will add PHP Azure SDK to the PHP include_path.
 *
 * Note: Here we assume that PHP Azure SDK is avilable in Web Role Root folder
 * of the Windows Azure Project. If this SDK is in some other folder, then
 * append this relative path at the end.
 */
if (isset($_SERVER["RoleRoot"])) {
    set_include_path(
        get_include_path() . 
        PATH_SEPARATOR . $_SERVER["RoleRoot"] . "\\approot\\"
    );
}

// Check prerequisite for plugin
register_activation_hook(__FILE__, 'check_prerequisite'); 

require_once 'windows-azure-storage-settings.php';
require_once 'windows-azure-storage-dialog.php';
require_once 'windows-azure-storage-util.php';

add_action('admin_menu', 'windows_azure_storage_plugin_menu');
add_filter('media_buttons_context', 'windows_azure_storage_media_buttons_context');

// Add three tabs to the Windows Azure Storage Dialog
add_action("media_upload_browse", "browse_tab");
add_action("media_upload_search", "search_tab");
add_action("media_upload_upload", "upload_tab");

// Hooks for handling default file uploads
if (get_option('azure_storage_use_for_default_upload') == 1) {
    add_filter(
        'wp_update_attachment_metadata', 
        'windows_azure_storage_wp_update_attachment_metadata', 9, 2
    );
    
    // Hook for handling blog posts via xmlrpc. This is not full proof check
    add_filter('content_save_pre', 'windows_azure_storage_content_save_pre');

    // Hook for handling media uploads
    add_filter('wp_handle_upload', 'windows_azure_storage_wp_handle_upload');
}

// Hook for acecssing attachment (media file) URL
add_filter(
    'wp_get_attachment_url',
    'windows_azure_storage_wp_get_attachment_url', 9, 2
);

// Hook for acecssing metadata about attachment (media file)
add_filter(
    'wp_get_attachment_metadata', 
    'windows_azure_storage_wp_get_attachment_metadata', 9, 2
);

// Hook for handling deleting media files from standard WordpRess dialog
add_action('delete_attachment', 'windows_azure_storage_delete_attachment');


/**
 * Check prerequisite for the plugin and report error
 *
 * @return void
 */ 
function check_prerequisite()
{
    // Check for Windows Azure SDK for PHP
    if (class_exists('Microsoft_WindowsAzure_Storage_Blob')) {
        return;
    }

    // Windows Azure SDK for PHP is not available
    $message = '<p style="color: red"><a href="http://phpazure.codeplex.com/">'
        . 'Windows Azure SDK for PHP</a> is not available in the include_path. ' 
        . 'Please install the SDK and update include_path in php.ini.</p>';

    if (function_exists('deactivate_plugins')) { 
        deactivate_plugins(__FILE__); 
    } else {
        $message = $message . '<p style="color: red"><strong>' 
            . 'Please deactivate this plugin Immediately</strong></p>';
    }

    die($message);
}

/**
 * Wordpress hook for wp_get_attachment_url
 * 
 * @param string  $url    post url 
 *
 * @param integer $postID post id
 *
 * @return string Returns metadata url
 */
function windows_azure_storage_wp_get_attachment_url($url, $postID)
{
    $mediaInfo = get_post_meta($postID, 'windows_azure_storage_info', true);

    if (!empty($mediaInfo)) {
        return $mediaInfo['url'];
    } else {
        return $url;
    }
}

/**
 * Wordpress hook for wp_get_attachment_metadata. Cache mediainfo for 
 * further reference.
 * 
 * @param string  $data    attachment data
 *
 * @param integer $postID  Associated post id
 *
 * @return array Return input data without modification
 */
function windows_azure_storage_wp_get_attachment_metadata($data, $postID)
{
    if (is_numeric($postID)) {
        // Cache this metadata. Needed for deleting files
        $mediaInfo = get_post_meta($postID, 'windows_azure_storage_info', true);
    }

    return $data;
}

/**
 * Wordpress hook for wp_update_attachment_metadata, hook for handling
 * default media file upload in wordpress.
 * 
 * @param string  $data    attachment data
 *
 * @param integer $postID  Associated post id
 *
 * @return array data after updating information about blob storage URL and tags
 */
function windows_azure_storage_wp_update_attachment_metadata($data, $postID)
{
    $storageClient = WindowsAzureStorageUtil::getStorageClient();
    $default_azure_storage_account_container_name 
        = WindowsAzureStorageUtil::getDefaultContainer();
        
    // Get full file path of uploaded file
    $uploadFileName = get_attached_file($postID, true);
    
    try {
        // Cache relative file name
        $relativeFileName = $data['file'];

        // Get full file path of uploaded file
        $data['file'] = get_attached_file($postID, true);

        $storageClient->putBlob(
            $default_azure_storage_account_container_name, 
            $relativeFileName, 
            $uploadFileName, 
            array(
                'tag' => "WordPressDefaultUpload", 
                'mime-type' => get_post_mime_type($postID)
            )
        );
        
        $url = WindowsAzureStorageUtil::getStorageUrlPrefix() 
            . "/" . $relativeFileName;
            
        // Set new url in returned data
        $data['url'] = $url;
        
        // Handle thumbnail and medium size files
        $sizes = $data["sizes"];
        if (!empty($sizes)) {
            $wp_upload_dir = wp_upload_dir();
            
            foreach ($sizes as $size) {
                // Do not prefix file name with wordpress upload folder path
                $sizeFileName = dirname($data['file']) . "/" . $size["file"];
                $blobName = $wp_upload_dir['subdir'] . "/" . $size["file"];
                $blobName = substr($blobName, 1);
                
                $storageClient->putBlob(
                    $default_azure_storage_account_container_name, 
                    $blobName, $sizeFileName, 
                    array('tag' => "WordPressDefaultUploadSizes")
                );
                
                // Delete the local file
                unlink($sizeFileName);
            }
        }
        
        delete_post_meta($postID, 'windows_azure_storage_info');

        add_post_meta(
            $postID, 'windows_azure_storage_info', 
            array(
                'container' => $default_azure_storage_account_container_name, 
                'blob' => $relativeFileName, 
                'url' => $url
            )
        );
        
        // Delete the local file
        unlink($uploadFileName);
    } catch (Exception $e) {
        echo "<p>Error in uploading file: " 
            . $newFileName . ", Error: " . $e->getMessage() . "</p><br/>";
    }

    return $data;
}

/**
 * Hook for updating post content prior to saving it in the database
 *
 * @param string $text post content
 *
 * @return string Updated post content
 */  
function windows_azure_storage_content_save_pre($text)
{
    return getUpdatedUploadUrl($text);
}

/**
 * Hook for handling media uploads
 * 
 * @param array $uploads upload metadata
 * 
 * @return array updated metadata
 */ 
function windows_azure_storage_wp_handle_upload($uploads)
{
    $wp_upload_dir = wp_upload_dir();
    $uploads['url'] = WindowsAzureStorageUtil::getStorageUrlPrefix() 
        . $wp_upload_dir['subdir'] . "/" . $uploads['file'];

    return $uploads;
}

/**
 * Common function to replace wordpress file uplaod url with 
 * Windows Azure Storage URLs
 *
 * @param string $url original upload URL
 *
 * @return string Updated upload URL
 */
function getUpdatedUploadUrl($url)
{
    $wp_upload_dir = wp_upload_dir();
    $upload_dir_url = $wp_upload_dir['url'];
    $storage_url_prefix = WindowsAzureStorageUtil::getStorageUrlPrefix();
    
    return str_replace($upload_dir_url, $storage_url_prefix, $url);
}

/**
 * Hook for handling deleting media files from standard WordpRess dialog
 *
 * @param string $postID post id
 *
 * @return void
 */ 
function windows_azure_storage_delete_attachment($postID)
{
    if (is_numeric($postID)) {
        $mediaInfo = get_post_meta($postID, 'windows_azure_storage_info', true);

        if (!empty($mediaInfo)) {
            $containerName = $mediaInfo['container'];
            $blobName = $mediaInfo['blob'];
            WindowsAzureStorageUtil::deleteBlob($containerName, $blobName);
        }
    }
}

/**
 * Add Browse tab to the popup windows
 *
 * @return void
 */
function browse_tab()
{
    add_action('admin_print_scripts', 'windows_azure_storage_dialog_scripts');
    wp_enqueue_style('media');
    wp_iframe('windows_azure_storage_dialog_browse_tab');
}

/**
 * Add Search tab to the popup windows
 *
 * @return void
 */
function search_tab()
{
    add_action('admin_print_scripts', 'windows_azure_storage_dialog_scripts');
    wp_enqueue_style('media');
    wp_iframe('windows_azure_storage_dialog_search_tab');
}

/**
 * Add Upload tab to the popup windows
 *
 * @return void
 */
function upload_tab()
{
    add_action('admin_print_scripts', 'windows_azure_storage_dialog_scripts');
    wp_enqueue_style('media');
    wp_iframe('windows_azure_storage_dialog_upload_tab');
}

/**
 * Hook for adding new toolbar button in edit post page
 * 
 * @param string $context context for edit page
 * 
 * @return string updated context
 */
function windows_azure_storage_media_buttons_context($context)
{
    global $post_ID, $temp_ID;
    
    $image_btn = "../wp-content/plugins/windows-azure-storage/images/WindowsAzure.jpg";
    $image_title = 'Windows Azure Storage';
    
    $uploading_iframe_ID = (int)(0 == $post_ID ? $temp_ID : $post_ID);
    $media_upload_iframe_src = "media-upload.php?post_id=$uploading_iframe_ID";
    
    $browse_iframe_src = apply_filters(
        'browse_iframe_src', 
        "$media_upload_iframe_src&amp;tab=browse"
    );
    $search_iframe_src = apply_filters(
        'browse_iframe_src', 
        "$media_upload_iframe_src&amp;tab=search"
    );
    $upload_iframe_src = apply_filters(
        'browse_iframe_src', 
        "$media_upload_iframe_src&amp;tab=upload"
    );
    
    $out = ' <a href="' . $media_upload_iframe_src . 
        '&tab=WindowsAzureStorageTab&TB_iframe=true&height=500&width=640"' . 
        'class="thickbox" title="' . $image_title . '"><img src="' . $image_btn 
        . '" width="20" Height="20" alt="' . $image_title . '" /></a>';
        
    $out = ' <a href="' . $browse_iframe_src 
        . '&TB_iframe=true&height=500&width=640" class="thickbox" title="' 
        . $image_title . '"><img src="' . $image_btn 
        . '" width="20" Height="20" alt="' . $image_title . '" /></a>';
    
    return $context . $out;
}

/**
 * Add option page for Windows Azure Storage Plugin
 * 
 * @return void
 */
function windows_azure_storage_plugin_menu()
{
    add_options_page(
        'Windows Azure Storage Plugin Settings', 
        'Windows Azure', 8, 
        'b5506889-50de-42db-bf63-e9f248ca94e9', 
        'windows_azure_storage_plugin_options_page'
    );
}
?>