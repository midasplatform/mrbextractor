<?php
/*=========================================================================
 MIDAS Server
 Copyright (c) Kitware SAS. 26 rue Louis Guérin. 69100 Villeurbanne, FRANCE
 All rights reserved.
 More information http://www.kitware.com

 Licensed under the Apache License, Version 2.0 (the "License");
 you may not use this file except in compliance with the License.
 You may obtain a copy of the License at

         http://www.apache.org/licenses/LICENSE-2.0.txt

 Unless required by applicable law or agreed to in writing, software
 distributed under the License is distributed on an "AS IS" BASIS,
 WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 See the License for the specific language governing permissions and
 limitations under the License.
=========================================================================*/

/** Extractor  component */
class Mrbextractor_ExtractComponent extends AppComponent
{
  /**
   * Extract mrb information
   * @param type $revision
   */
  public function extractMRB($revision)
    {    
    $config = Zend_Registry::get('configsModules');
    $pythonExecutable = $config['mrbextractor']->python;    
    if(is_array($revision))
      {
      $revision = MidasLoader::loadModel('ItemRevision')->load($revision['itemrevision_id']);
      }

    if(!$revision) return;
   
    $bitstreams = $revision->getBitstreams();
    $item = $revision->getItem();
    $userDao = $revision->getUser();
    $revisions = $item->getRevisions();

    $mrbFound = false;
    foreach($bitstreams as $bitstream)
      {
      $filnameArray = explode(".", $bitstream->getName());
      $ext = end($filnameArray);      
      if(strtolower($ext) == "mrb")
        {
        $mrbFound = $bitstream->getName();
        }
      }      

    if($mrbFound == false)return;
    
    $rootFolder = UtilityComponent::getTempDirectory()."/mrb_".$revision->getKey();
    if(!file_exists($rootFolder))
      {
      mkdir($rootFolder);
      }
      
    // Create symlinks in tmp folder
    $tmpUserDao = MidasLoader::newDao('UserDao');
    $tmpUserDao->setAdmin(1);
    $tmpUserDao->setUserId(1);    
    MidasLoader::loadComponent("Export")->exportBitstreams($tmpUserDao, $rootFolder, array($item->getKey()), true);    
    $bitstreamsFolder = $rootFolder."/".$item->getKey();
    
    // Extract information
    exec("cd $bitstreamsFolder && ".$pythonExecutable." ".dirname(__FILE__)."/scripts/mrb-extract-views.py  \"".$bitstreamsFolder."/".$mrbFound."\"  \"".$bitstreamsFolder."\" ", $output);
    
    if(file_exists($bitstreamsFolder."/index.json"))
      {
      $sceneContent = JsonComponent::decode(file_get_contents($bitstreamsFolder."/index.json"));
      $description = "";
      foreach($sceneContent as $element)
        {
        if(isset($element['description']) && !empty($element['description']))
          {
          $description .= $element['description']." ;";
          }
        
        if(isset($element['name']) && !empty($element['name']))
          {
          $description .= $element['name']." ;";
          }
        }
      $item->setDescription($description);  
      
      // Create metadata revision which will contain scene and images informations.
      $metadataRevision = false;
      $categories = array("Others");

      foreach($revisions as $revision)
        {
        if($revision->getChanges() == "Scenes' metadata") 
          {
          $metadataRevision = $revision;
          continue;          
          }
          
        $tmp = MidasLoader::loadComponent("Metadata", "slicerdatastore")->getRevisionCategories($revision);
        if(!empty($tmp))
          {
          $categories = $tmp;
          }       
        }
      if(!$metadataRevision)
        {
        $lastRevision = MidasLoader::loadModel("Item")->getLastRevision($item);
        for($i = $lastRevision->getRevision(); $i > 0; $i--)
          {
          $revision = MidasLoader::loadModel('Item')->getRevision($item, $i);
          if($revision)
            {
            $revision->setRevision($revision->getRevision() + 1);
            MidasLoader::loadModel('ItemRevision')->save($revision);
            }
          }

        Zend_Loader::loadClass('ItemRevisionDao', BASE_PATH . '/core/models/dao');
        $metadataRevision = new ItemRevisionDao;
        $metadataRevision->setChanges('Scenes\' metadata');
        $metadataRevision->setUser_id($userDao->getKey());
        $metadataRevision->setDate(date('c'));
        $metadataRevision->setLicenseId(null);
        $metadataRevision->setRevision(1);
        $metadataRevision->setItemId($item->getItemId());
        MidasLoader::loadModel('ItemRevision')->save($metadataRevision);
        }
      else
        {
        $metadataBitstreams = $metadataRevision->getBitstreams();
        foreach($metadataBitstreams as $bitstream)
          {
          MidasLoader::loadModel("Bitstream")->delete($bitstream);
          }
        }

      // Save the extracted information in the revision.
      $rootDirectory = opendir($bitstreamsFolder) or die('Erreur');
      $assetstoreDao = MidasLoader::loadModel('Assetstore')->getDefault();
      $thumbnailFile = false;
      while($Entry = @readdir($rootDirectory))
        {
        $extensions = array('png', 'json');
        if(!is_dir($bitstreamsFolder.'/'.$Entry) && $Entry != '.' && $Entry != '..') 
           {
           $ext = strtolower(substr(strrchr($Entry, '.'), 1));
           if(in_array($ext, $extensions))
             {     
             if((!$thumbnailFile && $ext == "png")
                  || ($ext == "png" && strpos($Entry, "_main.") !== false)) 
               {
               $thumbnailFile = $bitstreamsFolder.'/thumbnail.png';
               copy($bitstreamsFolder."/".$Entry, $thumbnailFile);
               }
             $bitstreamDao = new BitstreamDao;
             $bitstreamDao->setName($Entry);
             $bitstreamDao->setPath($bitstreamsFolder.'/'.$Entry);
             $bitstreamDao->setChecksum("");
             $bitstreamDao->fillPropertiesFromPath();
             $bitstreamDao->setAssetstoreId($assetstoreDao->getKey());
             // Upload the bitstream if necessary (based on the assetstore type)
             MidasLoader::loadComponent("Upload")->uploadBitstream($bitstreamDao, $assetstoreDao);
             MidasLoader::loadModel("ItemRevision")->addBitstream($metadataRevision, $bitstreamDao);
             }
           }
        }
      closedir($rootDirectory);
      
      if($thumbnailFile)
        {
        // create thumbnail
        $src = imagecreatefrompng($thumbnailFile);
        list ($x, $y) = getimagesize($thumbnailFile);  //--- get size of img ---
        $pathThumbnail = $bitstreamsFolder."/thumbnail_small.jpg";
        $thumb = 200;  //--- max. size of thumb ---
        if($x > $y)
          {
          $tx = $thumb;  //--- landscape ---
          $ty = round($thumb / $x * $y);
          }
        else
          {
          $tx = round($thumb / $y * $x);  //--- portrait ---
          $ty = $thumb;
          }
        $thb = imagecreatetruecolor($tx, $ty);  //--- create thumbnail ---
        imagecopyresampled($thb, $src, 0, 0, 0, 0, $tx, $ty, $x, $y);
        imagejpeg($thb, $pathThumbnail, 80);
        imagedestroy($thb);
        imagedestroy($src);
        $thumb = MidasLoader::loadModel("Bitstream")->createThumbnail($assetstoreDao, $pathThumbnail);
        $item->setThumbnailId($thumb->getKey());
        MidasLoader::loadModel("Item")->save($item);
        }
        
      // Set the metadata (the goal is to simplify the search using solr
      $lastRevision = MidasLoader::loadModel("Item")->getLastRevision($item);
      $metadataDao = MidasLoader::loadModel('Metadata')->getMetadata(MIDAS_METADATA_TEXT, "mrbextrator", "slicerdatastore");
      if(!$metadataDao)  MidasLoader::loadModel('Metadata')->addMetadata(MIDAS_METADATA_TEXT, "mrbextrator", "slicerdatastore", "");
      MidasLoader::loadModel('Metadata')->addMetadataValue($lastRevision, MIDAS_METADATA_TEXT, "mrbextrator", "slicerdatastore", "true"); 
      $metadataDao = MidasLoader::loadModel('Metadata')->getMetadata(MIDAS_METADATA_TEXT, "mrbextrator", "category");
      if(!$metadataDao)  MidasLoader::loadModel('Metadata')->addMetadata(MIDAS_METADATA_TEXT, "mrbextrator", "category", "");
      
      MidasLoader::loadComponent("Metadata", "slicerdatastore")->setRevisionCategories($lastRevision, $categories);            
      MidasLoader::loadModel("Item")->save($item); // trigger solr update
      }
          
    // Delete temps folder
    $rootDirectory = opendir($bitstreamsFolder) or die('Erreur');
    while($Entry = @readdir($rootDirectory))
      {
      if(!is_dir($bitstreamsFolder.'/'.$Entry) && $Entry != '.' && $Entry != '..') 
          {
          unlink($bitstreamsFolder.'/'.$Entry);
          }
      }
    closedir($rootDirectory);
    
    rmdir( $bitstreamsFolder );
    rmdir( $rootFolder );
    }
    
} // end class