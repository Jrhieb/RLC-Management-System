"""Export one documentation diagram with the already installed desktop CLI."""
from pathlib import Path
import json
import subprocess
import sys
import xml.etree.ElementTree as ET

out = Path(__file__).resolve().parent
source = next(out.glob(sys.argv[1]+'-*.drawio'))
pdf = source.with_suffix('.pdf')
command = [r'C:\Program Files\draw.io\draw.io.exe', '--export', '--format', 'pdf',
           '--all-pages', '--output', str(pdf), str(source),
           '--user-data-dir='+str(out/'.drawio-profile')]
status = {'source': source.name, 'expectedPages': [p.get('name') for p in ET.parse(source).getroot()]}
try:
    result = subprocess.run(command, capture_output=True, timeout=20,
                            creationflags=subprocess.CREATE_NO_WINDOW)
    status['exitCode'] = result.returncode
    status['status'] = 'Process returned; PDF requires inspection'
except subprocess.TimeoutExpired:
    status['status'] = 'Desktop export timed out; no runtime workarounds attempted'
status['pdfBytes'] = pdf.stat().st_size if pdf.exists() else 0
status['pdfExists'] = status['pdfBytes'] > 0
status['pdfPagesAndLegibilityVerified'] = False
path = out/'export-status.json'
history = json.loads(path.read_text()) if path.exists() else {}
history[source.name] = status
path.write_text(json.dumps(history, indent=2)+'\n')
print(json.dumps(status), flush=True)
