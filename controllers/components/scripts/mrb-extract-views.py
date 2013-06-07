import xml.etree.cElementTree as ET
import sys
import zipfile
import os.path
import urllib
import json
import os

def getumask():
    current_umask = os.umask(0)
    os.umask(current_umask)
    return current_umask

if __name__ == '__main__':
    if len(sys.argv) < 3:
        print>>sys.stderr, "usage: %s mrbfile outputfolder" % sys.argv[0]
        sys.exit(1)

    inputFilename = sys.argv[1]
    outputFolder = sys.argv[2]

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
            nodeCache[nodeid] = node
            
    sceneViews = [node for node in nodeCache.values() if node.tag == 'SceneView']
    sceneViewInfo = []
    for view in sceneViews:
        s = dict(name=view.get('name'),
                 description = view.get('sceneViewDescription'))
        storageNode = nodeCache[view.get('storageNodeRef')]
        s['id'] = view.get('id')
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
                
    toc = json.dumps(sceneViewInfo, sort_keys=True,indent=4, separators=(',', ': '))

    print toc
    try:
      f = open(outputFolder+"/index.json", "w")
      try:
        f.write(toc)
      finally:
        f.close()
    except IOError:
        pass
      
    zipinfos = zipfileSrc.infolist()
    for zipinfo in zipinfos:
        print zipinfo.filename
        for viewInfo in sceneViewInfo:
            if viewInfo['mrbFilename'] == zipinfo.filename:
              if viewInfo['mrmlFilename'].find("Data Bundle") != -1:
                  zipinfo.filename = viewInfo['id']+"_main.png"
              else:
                  zipinfo.filename = viewInfo['id']+".png"              
              zipfileSrc.extract(zipinfo)

