#!/usr/bin/env python

#=============================================================================
# Midas Server
# Copyright Kitware SAS, 26 rue Louis Guérin, 69100 Villeurbanne, France.
# All rights reserved.
# For more information visit http://www.kitware.com/.
#
# Licensed under the Apache License, Version 2.0 (the "License");
# you may not use this file except in compliance with the License.
# You may obtain a copy of the License at
#
#         http://www.apache.org/licenses/LICENSE-2.0.txt
#
# Unless required by applicable law or agreed to in writing, software
# distributed under the License is distributed on an "AS IS" BASIS,
# WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
# See the License for the specific language governing permissions and
# limitations under the License.
#=============================================================================

from __future__ import print_function
import xml.etree.cElementTree as ET
import sys
import zipfile
import urllib
import json
from os import chdir, getcwd
from os.path import realpath

class PushdContext:
    """
    Context manager allowing to easily pushd/popd.

    Source: https://gist.github.com/Tatsh/7131812
    """
    cwd = None
    original_dir = None

    def __init__(self, dirname):
        self.cwd = realpath(dirname)

    def __enter__(self):
        self.original_dir = getcwd()
        chdir(self.cwd)
        return self

    def __exit__(self, type, value, tb):
        chdir(self.original_dir)

def pushd(dirname):
    return PushdContext(dirname)

def mrbExtractor(inputFilename, outputFolder):
    """
    Output table of content of the given MRB file.

    Table of content of a MRB file consists of an index file named ``index.json`` and a list of screenshots.

    :param inputFilename: MRB file to process.
    :param outputFolder: Folder where to store ``index.json`` and associated screenshots.
    :return:
    """

    zipfileSrc = zipfile.ZipFile(inputFilename, 'r')

    mrmlFilename = None
    for archivedFilename in zipfileSrc.namelist():
        if archivedFilename.endswith('.mrml'):
            mrmlFilename = archivedFilename
            break

    mrmlfp = zipfileSrc.open(mrmlFilename)
    xmlTree = ET.parse(mrmlfp)
    xmlRoot = xmlTree.getroot()
    nodeCache = {}

    for node in xmlRoot:
        if node.tag in ('SceneView', 'SceneViewStorage'):
            nodeid = node.get('id')
            node.attrib['order'] = 0
            nodeCache[nodeid] = node

    for node in xmlRoot:
        if node.tag in ('Hierarchy'):
            nodeid = node.get('associatedNodeRef')
            sortingValue = node.get('sortingValue')
            if nodeid in nodeCache:
                nodeCache[nodeid].attrib['order'] = sortingValue

    sceneViews = [node for node in nodeCache.values() if node.tag == 'SceneView']
    sceneViewInfo = []
    for view in sceneViews:
        s = dict(name=view.get('name'),
                 description=view.get('sceneViewDescription'))
        key = view.get('storageNodeRef')
        if key is not None:
            storageNode = nodeCache[view.get('storageNodeRef')]
            s['id'] = view.get('id')
            s['order'] = view.get('order')
            s['mrmlFilename'] = urllib.unquote(storageNode.get('fileName'))
            sceneViewInfo.append(s)

    for viewInfo in sceneViewInfo:
        for z in zipfileSrc.namelist():
            if z.endswith(viewInfo['mrmlFilename']):
                viewInfo['mrbFilename'] = z

    for viewInfo in sceneViewInfo:
        for z in zipfileSrc.namelist():
            mrmlFilename = viewInfo['mrmlFilename']
            if z.endswith(mrmlFilename):
                newFilename = mrmlFilename.replace('/', '_').replace(' ', '_')
                viewInfo['filename'] = newFilename

    toc = json.dumps(sceneViewInfo, sort_keys=True, indent=4, separators=(',', ': '))

    try:
        with open(outputFolder + "/index.json", "w") as f:
            f.write(toc)
    except IOError:
        pass

    zipinfos = zipfileSrc.infolist()
    for zipinfo in zipinfos:
        for viewInfo in sceneViewInfo:
            if viewInfo['mrbFilename'] == zipinfo.filename:
                if viewInfo['mrmlFilename'].find("Data Bundle") != -1:
                    zipinfo.filename = viewInfo['id'] + "_main.png"
                else:
                    zipinfo.filename = viewInfo['id'] + ".png"
                with pushd(outputFolder):
                    zipfileSrc.extract(zipinfo)

if __name__ == '__main__':
    if len(sys.argv) < 3:
        print("usage: %s mrbfile outputfolder" % sys.argv[0], file=sys.stderr)
        sys.exit(1)

    inputFilename = sys.argv[1]
    outputFolder = sys.argv[2]
    mrbExtractor(inputFilename, outputFolder)
