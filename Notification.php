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
/** notification manager*/
class Mrbextractor_Notification extends MIDAS_Notification
  {
  public $moduleName = 'mrbextractor';

  /** init notification process*/
  public function init()
    {
    $this->addTask("TASK_EXTRACT_MRB", 'extractMRB', "");
    $this->addEvent('EVENT_CORE_UPLOAD_FILE', 'TASK_EXTRACT_MRB');
    }//end init

  /** Extract the file information */
  public function extractMRB($params)
    {
    MidasLoader::loadComponent("Extract", "mrbextractor")->extractMRB($params[1]);
    return;
    }
    
  } //end class