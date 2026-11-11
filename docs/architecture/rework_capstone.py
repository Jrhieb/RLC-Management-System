"""Rework the reviewed RLCP diagrams with UML notation and manuscript figures.

Plan: preserve the reviewed draft; correct notation; export with Desktop;
validate six pages and inspect the rendered figures. Application code is untouched.
"""
from pathlib import Path
import copy
import sys
import xml.etree.ElementTree as E
import json
import html

B=Path(__file__).resolve().parent
sys.path.insert(0,str(B.parents[1]/'.agents/skills/drawio-skill/scripts'))
import seqlayout
from encode_drawio_url import edit_url
for ext in ('drawio','pdf'):
    src=B/f'RLCP-System-Architecture.{ext}'
    backup=B/f'RLCP-System-Architecture.pre-capstone.{ext}'
    if not backup.exists(): backup.write_bytes(src.read_bytes())
tree=E.parse(B/'RLCP-System-Architecture.pre-capstone.drawio')
doc=tree.getroot()
titles=[d.get('name') for d in doc.findall('diagram')]
STYLE='html=1;whiteSpace=wrap;fontFamily=Arial;fontSize=22;fontColor=#292929;strokeColor=#292929;strokeWidth=1.5;fillColor=#FFFFFF;'
def n(r,i,v,x,y,w,h,s='',p='1'):
    c=E.SubElement(r,'mxCell',id=i,value=v,style=STYLE+s,vertex='1',parent=p)
    E.SubElement(c,'mxGeometry',x=str(x),y=str(y),width=str(w),height=str(h),**{'as':'geometry'})
    return c
def txt(r,i,v,x,y,w,h,size=22): return n(r,i,v,x,y,w,h,f'text;fillColor=none;strokeColor=none;align=left;fontSize={size};')
def e(r,i,a,b,v='',s='',pts=()):
    c=E.SubElement(r,'mxCell',id=i,value=v,source=a,target=b,parent='1',edge='1',style=STYLE+'fillColor=none;edgeStyle=orthogonalEdgeStyle;rounded=0;orthogonalLoop=1;jettySize=auto;endArrow=open;labelBackgroundColor=#FFFFFF;fontSize=20;'+s)
    g=E.SubElement(c,'mxGeometry',relative='1',**{'as':'geometry'})
    if pts:
        arr=E.SubElement(g,'Array',{'as':'points'})
        for x,y in pts:E.SubElement(arr,'mxPoint',x=str(x),y=str(y))
    return c
def fresh(index,w,h,desc):
    d=doc.findall('diagram')[index]; d.clear();d.set('id',f'rlcp-{index+1}');d.set('name',titles[index])
    m=E.SubElement(d,'mxGraphModel',page='1',pageScale='1',pageWidth=str(w),pageHeight=str(h),background='#FFFFFF')
    r=E.SubElement(m,'root');E.SubElement(r,'mxCell',id='0');E.SubElement(r,'mxCell',id='1',parent='0')
    txt(r,'title',titles[index],40,25,w-80,60,32)
    txt(r,'description',desc,40,95,w-80,65,22)
    return r
def cell(r,i):return r.find(f"mxCell[@id='{i}']")

# Package dependencies retain the reviewed package graph and geometry.
r=doc.findall('diagram')[0].find('.//root')
cell(r,'description').set('value','Packages organize the implementation. Dashed open arrows are UML dependencies; labels distinguish synchronous use from queued dispatch after commit.')
cell(r,'p1-provider').set('value','Notation\n«use»: synchronous dependency\n{queued}: asynchronous work\nModels / Services are repeated\nonly to keep the two views readable.')
for c in r.findall('mxCell'):
    if c.get('vertex') and c.get('id').startswith(('p0-','p1-')):
        c.set('style',STYLE+'shape=folder;tabWidth=110;tabHeight=25;tabPosition=left;spacingTop=20;fontSize=22;')
    if c.get('edge'):
        queued=c.get('id') in ('p1-edge-15','p1-edge-19')
        c.set('style',c.get('style','')+'dashed=1;endArrow=open;endFill=0;strokeColor=#292929;fontSize=19;')
        c.set('value',('{queued}\n' if queued else '«use»\n')+c.get('value','').replace(' (sync)',''))
cell(r,'p0-ui').set('value','resources/views · resources/js\nBlade layouts and feature views\nBrowser forms and interactions')
cell(r,'p1-provider').set('style',STYLE+'shape=note;size=20;fontSize=19;')
for ident,label in {
 'p1-registration':'Controllers · registration\nRegistrationController\nrequestLink / verify / complete',
 'p1-email':'Services · registration\nEmailRegistrationService\nManages request / link / session',
 'p1-review':'Controllers / Actions · review\nAccountRequestController\nReviewCaseworkerAccount',
 'p1-notice':'Services · notifications\nAccountNotificationService\nregistrationCompleted / decisionSaved',
 'p1-verifyMail':'Mail · verification\nRegistrationVerificationMail\nSent synchronously by the queued job',
 'p1-decisionMail':'Mail · decision\nAccountDecisionMail\nQueued after commit',
 'p0-caseload':'Services · caseload\nCaseworkerCaseload::summary\nCalled by DashboardController'}.items():cell(r,ident).set('value',label)

# Individual use-case associations, with proper proportioned human actors.
r=fresh(1,2300,1800,'Use cases describe user goals. Staff roles require verified email; Caseworkers also require approval. Beneficiaries are records, not users of the software.')
n(r,'boundary','RLCP Beneficiary Management System',330,190,1640,1190,'container=1;pointerEvents=0;verticalAlign=top;spacingTop=20;fontSize=26;fontStyle=1;')
roles=[('applicant','Staff Applicant',100,380,450,[(280,'Request verification email'),(450,'Verify email and\ncomplete registration'),(620,'View account status')]),
       ('director','Director',100,1030,450,[(820,'Approve / reject staff account'),(990,'Register / assign beneficiary'),(1160,'Archive / restore beneficiary')]),
       ('caseworker','Approved\nCaseworker',2130,800,1500,[(400,'View assigned caseload'),(580,'Update assigned beneficiary'),(760,'Manage NFC card'),(940,'Record attendance'),(1120,'Record assistance')])]
for ident,label,ax,ay,ux,cases in roles:
    n(r,ident,label,ax,ay,65,110,'shape=umlActor;verticalLabelPosition=bottom;verticalAlign=top;labelWidth=220;')
    for j,(uy,label) in enumerate(cases):
        cid=f'{ident}-uc{j}'
        n(r,cid,label,ux-330,uy-190,330,105,'ellipse;',p='boundary')
        e(r,cid+'-link',ident,cid,'','edgeStyle=none;endArrow=none;')
# Shared staff goals occupy the center and connect directly to both actors.
for j,(uy,label) in enumerate([(350,'Manage program activities'),(690,'View beneficiary history'),(1030,'View / export reports')]):
    cid=f'shared{j}';n(r,cid,label,900-330,uy-190,330,105,'ellipse;',p='boundary')
    # References to staff roles use duplicate actor symbols explicitly labeled.
n(r,'director-shared','Director\n(same actor)',910,170,60,95,'shape=umlActor;verticalLabelPosition=bottom;verticalAlign=top;labelWidth=180;')
n(r,'caseworker-shared','Approved Caseworker\n(same actor)',1150,170,60,95,'shape=umlActor;verticalLabelPosition=bottom;verticalAlign=top;labelWidth=220;')
# Keep all actors outside the system boundary: move shared actors above it.
cell(r,'boundary').find('mxGeometry').set('y','340')
cell(r,'boundary').find('mxGeometry').set('height','1200')
# Restore intended absolute use-case positions below the raised boundary.
for c in r.findall('mxCell'):
    if c.get('parent')=='boundary':
        g=c.find('mxGeometry');g.set('y',str(float(g.get('y'))+30))
for j in range(3):
    for k,actor in enumerate(('director-shared','caseworker-shared')):
        corridor=810+j*25 if k==0 else 1290+j*25
        e(r,f'shared-{j}-{k}',actor,f'shared{j}','','endArrow=none;exitX='+('0' if k==0 else '1')+';exitY='+str(0.3+j*0.15)+';entryX='+('0' if k==0 else '1')+';entryY=0.5;',[(corridor,200+j*14),(corridor,582.5+j*340)])
txt(r,'permissions','Director may also update beneficiaries, manage NFC cards, record attendance and assistance across the organization.\nCaseworker beneficiary operations and reports are restricted to current assignments; attendance and assistance require active records.\nShared actor symbols denote the same roles, not additional accounts. Source: User, BeneficiaryPolicy, ProgramActivityPolicy and inventory.md.',40,1590,2200,100,21)

# Typed model properties retain actual Eloquent class names and associations.
r=doc.findall('diagram')[2].find('.//root')
attrs={
'user':'+ id: int; name: string\n+ email: string {unique}\n+ role: string; approval_status: enum\n+ email_verified_at: datetime [0..1]\n+ approval_reviewed_by: int [0..1]',
'ben':'+ id: int; beneficiary_id: string {unique}\n+ full_name: string; status: string\n+ caseworker_id: int [0..1]\n+ nfc_uid: string [0..1] {unique}\n+ registered_at: date; deleted_at: datetime [0..1]',
'attendance':'+ id: int; beneficiary_id: int\n+ program_activity_id: int\n+ recorded_by: int [0..1]\n+ checked_in_at: datetime; source: string\n{unique activity + beneficiary}',
'activity':'+ id: int; title: string\n+ activity_date: date; start_time: time [0..1]\n+ development_area: string\n+ created_by: int [0..1]',
'assist':'+ id: int; beneficiary_id: int\n+ recorded_by: int [0..1]\n+ submission_token: UUID [0..1] {unique}\n+ category, item_name: string; quantity: int\n+ released_at: datetime',
'notice':'+ id: UUID; recipient_id, account_id: int\n+ kind, event_key: string\n+ added_count, removed_count: int\n+ read_at: datetime [0..1]\n{unique recipient_id + event_key}',
'request':'+ id: int; email: string {unique}\n+ expires_at: datetime\n+ verified_at: datetime [0..1]\n+ session_expires_at: datetime [0..1]\n+ completed_at: datetime [0..1]'}
for ident,value in attrs.items():cell(r,ident+'-attrs').set('value',value)
cell(r,'classnote').set('value','+ denotes public Eloquent attributes / operations; [0..1] denotes an optional value. Only relevant attributes and methods are shown.\nAssociation labels name implemented methods or schema foreign keys. Assignment controls access; recorder / creator links preserve historical authorship.\nRegistrationRequest is matched by email in application logic; no User association exists. Source: app/Models and migration declarations.')

# Sequence panels generated from ordered messages, then wrapped in UML fragments.
r=fresh(3,3900,2800,'Each interaction is independent. Time flows down; solid filled arrows are calls, solid open arrows are asynchronous messages, and dashed open arrows are replies.')
seqlayout.TOP=40;seqlayout.HEADER_H=70;seqlayout.LIFELINE_W=240;seqlayout.ROW=65;seqlayout.NOTE_ROW=55;seqlayout.MIN_SPACING=340
seqlayout.LIFELINE=seqlayout.LIFELINE.replace('size=40','size=70')
seqlayout.ASYNC=seqlayout.ASYNC.replace('dashed=1;','dashed=0;')
def m(a,b,label,ret=False,queued=False):return {'from':a,'to':b,'label':label,'return':ret,'async':queued,'activate':False}
def guard(label):return {'note':label,'over':'b'}
specs=[
 ('A. Staff registration and account decision',['Applicant','Registration / Review','Models / database','Queue / mail','Director'],[
 m('a','b','1: request registration link'),m('b','c','2: save hashed link + expiry'),m('b','d','3: dispatch after commit',queued=True),m('d','a','4: deliver verification email',queued=True),m('a','b','5: verify link; complete registration'),m('b','c','6: consume session; create pending User'),m('e','b','7: submit confirmed review'),
 guard('[pending account; decision = approve]'),m('b','c','8a: save approval + notification'),guard('[pending account; decision = reject]'),m('b','c','8b: save rejection, reason + notification'),guard('[account already reviewed]'),m('b','e','8c: conflict; decision unchanged',True),guard('[decision committed]'),m('b','d','9: queue decision email after commit',queued=True)],[(7,12,'alt'),(13,14,'opt')],
 'Invalid / expired / used links or sessions fail. New users are email-verified but pending. Approval enables staff access; rejection retains restrictions.'),
 ('B. NFC attendance and duplicate tap',['Staff browser','NfcAttendanceController','Beneficiary / policies','ActivityAttendance'],[
 m('a','b','1: submit UID and activity'),m('b','c','2: normalize; scope and authorize lookup'),m('c','b','3: active, nonarchived beneficiary',True),
 guard('[first check-in]'),m('b','d','4a: create activity-beneficiary attendance'),m('b','a','5a: 201 created',True),guard('[duplicate tap]'),m('d','b','4b: return original attendance',True),m('b','a','5b: 200 already recorded',True)],[(3,8,'alt')],
 'Invalid, inaccessible or inactive records fail before persistence. firstOrCreate and a composite unique key prevent duplicates. Endpoint source = nfc_test.'),
 ('C. Assistance release and repeated submission',['Staff browser','Assistance controller','Policy / validation','AssistanceTransaction'],[
 m('a','b','1: submit release + submission token'),m('b','c','2: authorize active beneficiary; validate'),m('b','d','3: look up submission token'),
 guard('[token absent]'),m('b','d','4a: persist release and original recorder'),guard('[token exists; all fields match]'),m('b','a','4b: already saved; no second insert',True),guard('[token exists; payload differs]'),m('b','a','4c: validation error',True)],[(3,8,'alt')],
 'Replay comparison includes recorder and UTC release time. A token-uniqueness race rereads the original row. Attendance is not a prerequisite.'),
 ('D. CSV / PDF report export',['Staff browser','Report controllers','Scoped history','ExportRows / Dompdf'],[
 m('a','b','1: request report type, dates and format'),m('b','c','2: authorize; validate dates; scoped query'),m('c','b','3: attendance / assistance rows',True),
 guard('[format = CSV]'),m('b','d','4a: stream formula-safe CSV'),guard('[format = PDF; rows <= 1,000]'),m('b','d','4b: render PDF from reports.pdf'),guard('[format = PDF; rows > 1,000]'),m('b','a','4c: reject; request narrower date range',True)],[(3,8,'alt')],
 'Successful exports download with private, no-store headers. Queries are read-only. Activity-specific CSV uses the selected activity and optional search.')]
for index,(title,parts,messages,fragments,note) in enumerate(specs):
    ox=50+(index%2)*1930;oy=190+(index//2)*1280
    panel=n(r,f'panel{index}','sd',ox,oy,1840,1190,'shape=umlFrame;container=1;pointerEvents=0;fillColor=none;align=left;verticalAlign=top;fontStyle=1;fontSize=25;')
    n(r,f'paneltitle{index}',title,100,0,1700,35,'text;fillColor=none;strokeColor=none;align=left;fontStyle=1;fontSize=25;',f'panel{index}')
    old=E.fromstring(seqlayout.layout({'participants':[{'id':chr(97+j),'label':p} for j,p in enumerate(parts)],'messages':messages})).find('.//root')
    # Fragment locations use the same row arithmetic as the skill generator.
    ys=[];cursor=seqlayout.TOP+seqlayout.HEADER_H+50
    for message in messages:
        ys.append(cursor);cursor+=seqlayout.NOTE_ROW if 'note' in message else seqlayout.ROW
    for fi,(start,end,kind) in enumerate(fragments):
        fy=ys[start]-35;bottom=ys[end]+20
        n(r,f'frag{index}-{fi}',kind,20,fy,1795,bottom-fy,'shape=umlFrame;container=1;pointerEvents=0;fillColor=none;align=left;verticalAlign=top;fontSize=21;',f'panel{index}')
        for k in range(start+1,end+1):
            if 'note' in messages[k]:
                n(r,f'sep{index}-{k}','',20,ys[k]-35,1795,1,'line;dashed=1;fillColor=none;',f'panel{index}')
    for original in old.findall('mxCell'):
        if original.get('id') in ('0','1'):continue
        c=copy.deepcopy(original);cid=c.get('id');c.set('id',f'seq{index}-{cid}')
        for key in ('source','target'):
            if c.get(key):c.set(key,f'seq{index}-'+c.get(key))
        c.set('parent',f'panel{index}' if c.get('parent')=='1' else f'seq{index}-'+c.get('parent'))
        c.set('style',c.get('style','')+'fontFamily=Arial;fontSize=21;fontColor=#292929;strokeColor=#292929;')
        if cid.startswith('note'):
            c.set('style',STYLE+'text;fillColor=none;strokeColor=none;align=left;fontSize=20;')
            g=c.find('mxGeometry');g.set('x','100');g.set('width','1680');g.set('height','35')
        r.append(c)
    txt(r,f'seqnote{index}',note,ox,oy+1200,1840,65,20)

# Deployment uses explicit UML node types; communication paths are undirected.
r=doc.findall('diagram')[4].find('.//root')
labels={'dep-windows-host':'«device» Windows application host\nDocumented configuration; runtime unverified',
'dep-web-process':'«executionEnvironment»\nPHP web process\nLaravel application',
'dep-mail-worker':'«executionEnvironment»\nPHP queue worker\nSeparate process',
'dep-phone-browser':'«device» Phone\nWeb browser',
'dep-scanning-computer':'«device» Scanning computer\nDesktop browser',
'dep-usb-reader':'«device» USB reader\nKeyboard-style input\nHardware unverified',
'dep-smtp-service':'«executionEnvironment»\nSMTP transport\nExternal mail service',
'dep-sqlite':'«artifact» SQLite file\nApplication records\nDatabase queue tables',
'dep-file-storage':'«artifact» Local files\nApplication / uploads'}
for ident,label in labels.items():
    label=label.replace('«executionEnvironment»','<span style="font-size:16px">«executionEnvironment»</span>')
    cell(r,ident).set('value',label.replace('\n','<br>'))
for c in r.findall('mxCell'):
    if c.get('edge'):c.set('style',c.get('style','')+'startArrow=none;endArrow=none;')
cell(r,'dep-windows-host').set('style',STYLE+'swimlane;startSize=65;container=1;collapsible=0;pointerEvents=0;fillColor=#FFFFFF;fontStyle=1;')

# Swimlane process: start/end symbols, guarded decision, independent operations.
r=doc.findall('diagram')[5].find('.//root')
for ident in ('request','register'):
    cell(r,ident).find('mxGeometry').set('x','300')
    cell(r,ident).find('mxGeometry').set('width','240')
for ident in ('a6','a7'):cell(r,ident).set('value','[approved]' if ident=='a6' else '[rejected; reason supplied]')
for ident in ('startA','startB'):
    lane='applicant' if ident=='startA' else 'directorB'
    n(r,ident,'',205,62,24,24,'ellipse;fillColor=#292929;',lane)
    e(r,ident+'-flow',ident,'request' if ident=='startA' else 'register','','endArrow=open;')
for ident,parent,x,y in [('endApproved','systemA',2480,40),('endRejected','systemA',2480,125)]:
    n(r,ident,'',x,y,28,28,'ellipse;shape=doubleEllipse;fillColor=#292929;',parent)
    e(r,ident+'-flow','approve' if ident=='endApproved' else 'reject',ident)
# Replace the ambiguous shared line with a UML decision and guarded alternatives.
for i in range(3):r.remove(cell(r,f'bchoice{i}'))
n(r,'choose-operation','',1130,45,60,60,'rhombus;',p='staff')
e(r,'choose-link','access','choose-operation','','exitX=1;exitY=0.5;entryX=0;entryY=0.5;')
for i,(target,guardname,y) in enumerate([('attendance-op','[attendance]',1025),('assistance-op','[assistance]',1000),('report-op','[reporting]',975)]):
    tx=40+[1250,1660,2070][i]+170
    e(r,f'operation-{i}','choose-operation',target,guardname,'exitX=0.5;exitY=0;entryX=0.5;entryY=0;',[(1200,y),(tx,y)])
for i,source in enumerate(('attendance-save','assistance-save','report-save')):
    x=1250+i*410+155
    n(r,f'endB{i}','',x,195,28,28,'ellipse;shape=doubleEllipse;fillColor=#292929;',p='systemB')
    e(r,f'endB-edge{i}',source,f'endB{i}')
cell(r,'process-note').set('value','Choose one operation per interaction; the user can return later for any other operation. No attendance-before-assistance requirement exists.\nActivity definitions are organization-wide; beneficiary records follow role / assignment scope. Director alone registers, assigns, archives and restores.\nBlack circles denote starts; bullseyes denote completed interactions; bracketed labels are decision guards. Source: inventory.md and current controllers.')

# Unified manuscript style: numbered figure captions, light rules, no banners.
for index,d in enumerate(doc.findall('diagram')):
    model=d.find('mxGraphModel');r=model.find('root');w=float(model.get('pageWidth'));h=float(model.get('pageHeight'))
    for c in r.findall('mxCell'):
        style=c.get('style','')
        if not style:continue
        c.set('style',style+'fontFamily=Arial;fontColor=#292929;strokeColor=#444444;')
        if 'text;' in style:c.set('style',c.get('style')+'strokeColor=none;fillColor=none;')
        if c.get('id')=='title':
            c.set('value',titles[index]);c.set('style',STYLE+'text;strokeColor=none;fillColor=none;align=center;fontSize=32;fontStyle=1;fontColor=#A50021;')
        if 'swimlane;' in style:c.set('style',c.get('style')+'fillColor=#FFFFFF;strokeColor=#777777;')
    n(r,'header-rule','',40,85,w-80,2,'fillColor=#D4A039;strokeColor=#D4A039;')
    # Reserve a clean footer after the existing diagram content.
    model.set('pageHeight',str(int(h+100)))
    caption=txt(r,'figure-caption',f'Figure {index+1}. {titles[index]}',40,h+20,w-80,45,24)
    caption.set('style',caption.get('style')+'align=center;fontStyle=2;')
    ids=[c.get('id') for c in r.findall('mxCell')];assert len(ids)==len(set(ids))
    for c in r.findall('mxCell'):
        for key in ('source','target','parent'):
            if c.get(key):assert c.get(key) in ids,(index,c.get('id'),key)
assert len(doc.findall('diagram'))==6
E.indent(doc)
tree.write(B/'RLCP-System-Architecture.drawio',encoding='utf-8',xml_declaration=True)
xml=(B/'RLCP-System-Architecture.drawio').read_text(encoding='utf-8')
url=edit_url(xml)
(B/'Open-RLCP-Diagram.html').write_text('<!doctype html><meta charset="utf-8"><title>RLCP capstone diagrams</title><a href="'+html.escape(url,quote=True)+'">Open the revised six-tab RLCP diagram in draw.io</a><script>location.replace('+json.dumps(url)+');</script>',encoding='utf-8')
print('Preserved pre-capstone drafts. Wrote six revised UML / manuscript figures; IDs and connector endpoints valid.')
