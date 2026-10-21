"""Offline local updater checks: no GitHub, installers or dependency execution."""
import importlib.util
import io
from pathlib import Path
import tempfile
import zipfile
spec=importlib.util.spec_from_file_location('local_updater',Path(__file__).resolve().parents[1]/'pull.py')
m=importlib.util.module_from_spec(spec);spec.loader.exec_module(m)
def zipped(files):
 out=io.BytesIO()
 with zipfile.ZipFile(out,'w') as archive:
  for name,value in files.items():archive.writestr(name,value)
 return out.getvalue()
with tempfile.TemporaryDirectory() as directory:
 root=Path(directory);(root/'.env').write_text('private');(root/'data').mkdir();(root/'data'/'db').write_text('stored')
 data=zipped({'repo/index.php':'new','repo/.env':'overwrite','repo/data/db':'overwrite'})
 m.install(data,root,'a'*40,False)
 assert (root/'index.php').read_text()=='new'
 assert (root/'.env').read_text()=='private' and (root/'data'/'db').read_text()=='stored'
 assert (root/'.pull-local-history'/('a'*40+'.zip')).read_bytes()==data
 for name in ['../escape','/absolute','C:/windows']:
  target=root/'extract';target.mkdir(exist_ok=True)
  try:m.extract(zipped({name:'unsafe'}),target)
  except RuntimeError:pass
  else:raise AssertionError(name)
 print('Python updater: safe archive, preserved credentials/data and rollback history passed')
