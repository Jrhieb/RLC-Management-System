"""Assemble source-reviewed RLCP views; preserve all existing drafts."""
from pathlib import Path
import copy
import sys
import xml.etree.ElementTree as E

BASE = Path(__file__).resolve().parent
sys.path.insert(0, str(BASE.parents[1] / '.agents/skills/drawio-skill/scripts'))
import seqlayout

TITLES = [f'{name} of the Proposed System' for name in ['Package Diagram', 'Use Case Diagram', 'Class Diagram', 'Sequence Diagram', 'Deployment Diagram', 'Process Flow']]
DOC = E.Element('mxfile', host='drawio', version='26.0.0')
STYLE = 'whiteSpace=wrap;html=1;fontFamily=Arial;fontSize=20;fontColor=#292929;strokeColor=#A50021;fillColor=#FFFDF8;'

def node(root, ident, value, x, y, w, h, style='', parent='1'):
    c = E.SubElement(root, 'mxCell', id=ident, value=value, style=STYLE+style, vertex='1', parent=parent)
    E.SubElement(c, 'mxGeometry', x=str(x), y=str(y), width=str(w), height=str(h), **{'as':'geometry'})
    return c

def text(root, ident, value, x, y, w, h, size=20):
    return node(root, ident, value, x,y,w,h, f'text;strokeColor=none;fillColor=none;align=left;fontSize={size};')

def edge(root, ident, source, target, label='', style='', points=()):
    c = E.SubElement(root,'mxCell',id=ident,value=label,source=source,target=target,parent='1',edge='1',style='html=1;fontFamily=Arial;fontSize=18;fontColor=#292929;strokeColor=#555555;strokeWidth=2;labelBackgroundColor=#FFFFFF;edgeStyle=orthogonalEdgeStyle;rounded=1;orthogonalLoop=1;jettySize=auto;endArrow=block;'+style)
    g=E.SubElement(c,'mxGeometry',relative='1',**{'as':'geometry'})
    if points:
        a=E.SubElement(g,'Array',{'as':'points'})
        for x,y in points: E.SubElement(a,'mxPoint',x=str(x),y=str(y))
    return c

def page(i,w,h,description):
    d=E.SubElement(DOC,'diagram',id=f'rlcp-{i+1}',name=TITLES[i])
    m=E.SubElement(d,'mxGraphModel',page='1',pageScale='1',pageWidth=str(w),pageHeight=str(h),background='#FFFFFF',grid='1',gridSize='10')
    r=E.SubElement(m,'root'); E.SubElement(r,'mxCell',id='0'); E.SubElement(r,'mxCell',id='1',parent='0')
    node(r,'title',TITLES[i],30,20,w-60,64,'fillColor=#A50021;fontColor=#FFFFFF;fontSize=32;fontStyle=1;align=left;spacingLeft=20;')
    text(r,'description',description,40,95,w-80,65,22)
    return r

def import_cells(root, old, prefix, dx=0,dy=0, skip=()):
    for original in old.findall('mxCell'):
        oid=original.get('id')
        if oid in ('0','1') or oid in skip: continue
        c=copy.deepcopy(original); c.set('id',prefix+oid)
        for key in ('source','target','parent'):
            if c.get(key) and c.get(key) not in ('0','1'): c.set(key,prefix+c.get(key))
        g=c.find('mxGeometry')
        if c.get('parent')=='1' and g is not None and c.get('vertex'):
            g.set('x',str(float(g.get('x','0'))+dx)); g.set('y',str(float(g.get('y','0'))+dy))
        if g is not None:
            for p in g.findall('./Array/mxPoint'):
                p.set('x',str(float(p.get('x','0'))+dx)); p.set('y',str(float(p.get('y','0'))+dy))
        root.append(c)

# Preserve the completed request and messaging package layouts as two panels.
r=page(0,3200,1100,'RLCP packages call policies, services and models directly. Dashed arrows denote queued work; solid arrows denote synchronous calls or requests.')
for i,d in enumerate(E.parse(BASE/'01-package-diagram.drawio').findall('diagram')):
    import_cells(r,d.find('.//root'),f'p{i}-',i*1600,60,('title','takeaway','legend'))
    text(r,f'panel{i}', ['A · Requests and domain operations','B · Registration, review and messaging'][i],40+i*1600,175,1500,45,26)
text(r,'evidence','Source: inventory.md AUTH / REG / ACC rules; routes, controllers, Livewire settings, services, jobs and mail. The two panels expand the same application.',40,1010,3100,45)
# Controller rendering dependency is explicit alongside the existing request path.
edge(r,'render','p0-http','p0-ui','renders Blade / view data','exitX=0;exitY=0.15;entryX=1;entryY=0.8;')

# Reuse source-reviewed use-case labels, consolidating actors into one boundary.
r=page(1,2100,1500,'Human actors are outside the boundary. Beneficiaries are managed records, never login accounts. Staff access requires verified email and approval for Caseworkers.')
node(r,'system','RLCP Beneficiary Management System',350,180,1700,1200,'swimlane;startSize=55;container=1;collapsible=0;fontSize=26;fillColor=#FFFDF8;')
actor_names=['Staff Applicant','Director','Approved Caseworker']
case_groups=[
 ['Request registration email','Verify link and complete\nstaff registration','View own account status\nand decision notifications'],
 ['Approve / reject pending\nstaff accounts','Register beneficiaries;\nassign / unassign Caseworkers','Archive / restore\nbeneficiary records','View / edit all beneficiaries;\nlink NFC; record assistance','Manage activities; record\nattendance; export reports'],
 ['View assigned caseload\nand beneficiary history','Edit assigned beneficiaries;\nlink / replace / unlink NFC','Record assistance for\nassigned active beneficiaries','Manage organization-wide\nactivity definitions','Record assigned attendance;\nexport scoped reports']]
for row,(actor,cases) in enumerate(zip(actor_names,case_groups)):
    ay=260+row*365
    node(r,f'actor{row}',actor,50,ay+50,210,110,'shape=umlActor;verticalLabelPosition=bottom;verticalAlign=top;fillColor=#FFFFFF;')
    for col,label in enumerate(cases):
        # A regular grid retains wide corridors for independent actor associations.
        x=65+col*320
        node(r,f'uc{row}-{col}',label,x,100+row*365,280,140,'ellipse;fillColor=#FFFFFF;',parent='system')
        edge(r,f'uc-edge{row}-{col}',f'actor{row}',f'uc{row}-{col}','','endArrow=none;exitX=1;exitY=0.5;entryX=0.5;entryY=0;',[(300,ay-25),(350+x+140,ay-25)])
text(r,'ucnote','Director: organization scope. Caseworker: current assignment + nonarchived beneficiaries; active status required for attendance / assistance.\nAll staff may use own permitted settings and notifications. Pending / rejected accounts remain restricted. Source: inventory.md AUTH / REG / ACC; policies.',40,1410,2020,65)

# Seven actual model classes with named methods; schema-only associations marked.
r=page(2,2200,1600,'Current caseworker assignment governs access. Historical creator / recorder fields preserve who performed an action. Multiplicities appear at both ends of every association.')
def klass(ident,name,x,y,attributes,methods,w=440):
    h=280
    node(r,ident,name,x,y,w,h,'swimlane;startSize=45;fontStyle=1;fontSize=24;container=1;collapsible=0;fillColor=#FFF5DE;')
    node(r,ident+'-attrs',attributes,0,45,w,145,'align=left;spacingLeft=12;fillColor=#FFFFFF;strokeColor=none;fontSize=19;',ident)
    node(r,ident+'-methods',methods,0,190,w,90,'align=left;spacingLeft=12;fillColor=#FFFDF8;fontSize=18;',ident)

klass('user','User',880,650,'id; name; email (unique)\nrole; approval_status\nemail_verified_at?\napproval_reviewed_by?; approval_reviewed_at?','+ assignedBeneficiaries(): HasMany\n+ isEligibleCaseworker(): bool\n+ canAccessStaff(): bool')
klass('ben','Beneficiary',80,650,'id; beneficiary_id (unique); full_name\ncaseworker_id?; nfc_uid? (unique)\nregistered_at; status; deleted_at?','+ caseworker(): BelongsTo\n+ scopeVisibleTo(query, user): Builder')
klass('attendance','ActivityAttendance',80,200,'id; program_activity_id; beneficiary_id\nrecorded_by?; checked_in_at; source\nUNIQUE(program_activity_id, beneficiary_id)','+ activity(): BelongsTo\n+ beneficiary(): BelongsTo [withTrashed]\n+ recorder(): BelongsTo')
klass('activity','ProgramActivity',880,200,'id; title; activity_date; start_time?\ndevelopment_area; created_by?','+ attendances(): HasMany\n+ creator(): BelongsTo')
klass('assist','AssistanceTransaction',80,1120,'id; beneficiary_id; recorded_by?\nsubmission_token? (unique); category\nitem_name; quantity; released_at','+ beneficiary(): BelongsTo [withTrashed]\n+ recorder(): BelongsTo\n+ scopeVisibleTo(query, user): Builder')
klass('notice','AccountNotification',1660,650,'id: UUID; recipient_id; account_id\nkind; event_key; read_at?\nadded_count; removed_count\nUNIQUE(recipient_id, event_key)','+ scopeForRecipient(query, user): Builder\nNo Eloquent relationship methods')
klass('request','RegistrationRequest',1660,1120,'id; email (unique)\nexpires_at; verified_at?\nsession_expires_at?; completed_at?','No Eloquent relationship methods\nEmail matched by registration service;\nno User foreign key or association')
def assoc(ident,s,t,label,a,b,style='',points=()):
    c=edge(r,ident,s,t,label,'endArrow=none;'+style,points)
    for end,mult,pos in [('s',a,-0.70),('t',b,0.70)]:
        q=E.SubElement(r,'mxCell',id=ident+'-'+end,value=mult,style='edgeLabel;html=1;fontSize=20;fontColor=#A50021;labelBackgroundColor=#FFFFFF;',vertex='1',connectable='0',parent=ident)
        g=E.SubElement(q,'mxGeometry',x=str(pos),y='-18',relative='1',**{'as':'geometry'});E.SubElement(g,'mxPoint',x='0',y='0',**{'as':'offset'})
assoc('assignment','ben','user','current assignment\ncaseworker_id','0..*','0..1','exitX=1;exitY=0.5;entryX=0;entryY=0.5;')
assoc('att-ben','attendance','ben','beneficiary()','0..*','1','exitX=0.25;exitY=1;entryX=0.25;entryY=0;')
assoc('att-act','attendance','activity','activity() / attendances()','0..*','1','exitX=1;exitY=0.5;entryX=0;entryY=0.5;')
assoc('act-user','activity','user','creator() · created_by','0..*','0..1','exitX=0.5;exitY=1;entryX=0.5;entryY=0;')
assoc('att-user','attendance','user','recorder() · recorded_by','0..*','0..1','exitX=1;exitY=0.85;entryX=0;entryY=0.2;',[(690,438),(690,706)])
assoc('assist-ben','assist','ben','beneficiary()','0..*','1','exitX=0.25;exitY=0;entryX=0.25;entryY=1;')
assoc('assist-user','assist','user','recorder() · recorded_by','0..*','0..1','exitX=1;exitY=0.5;entryX=0.5;entryY=1;',[(1100,1260)])
assoc('recipient','notice','user','recipient_id [schema FK]','0..*','1','exitX=0;exitY=0.25;entryX=1;entryY=0.25;')
assoc('account','notice','user','account_id [schema FK]','0..*','1','exitX=0;exitY=0.75;entryX=1;entryY=0.75;')
assoc('reviewer','user','user','approval_reviewed_by [schema FK]','0..*','0..1','exitX=0.75;exitY=0;entryX=1;entryY=0.05;',[(1210,565),(1430,565),(1430,664)])
text(r,'classnote','? = nullable; 0..* = any number. Recorder / creator links are historical, independent of current assignment. User references marked ? become null on deletion.\nBeneficiary soft deletion preserves history. Only key fields shown; no credential values. Source: app/Models and migration declarations; inventory.md §5.',40,1470,2100,80)

# Condense the reviewed four-scenario sequence draft using the skill layout engine.
r=page(3,3000,2100,'Four independent scenarios; time runs downward within each panel. Solid arrows: synchronous messages. Dashed arrows: queued dispatch or return, as labeled.')
seqlayout.LIFELINE_W=190; seqlayout.HEADER_H=70; seqlayout.TOP=40; seqlayout.ROW=60; seqlayout.NOTE_ROW=60; seqlayout.MIN_SPACING=330
seqlayout.LIFELINE=seqlayout.LIFELINE.replace('size=40','size=70')
def msg(a,b,label,**kwargs): return dict({'from':a,'to':b,'label':label,'activate':False},**kwargs)
scenarios=[
 ('A · Staff registration, verification and Director decision',['Applicant / Director','Registration / Review','Models / database','Queue / mail'],[
 msg('a','b','1. Applicant requests email link'),msg('b','c','2. Save request + hashed link'),msg('b','d','3. Queue verification after commit',async_=True),msg('d','a','4. Send verification email'),msg('a','b','5. Follow link; submit completion'),msg('b','c','6. Consume link/session; create\nverified pending User; notify Director'),msg('a','b','7. Director submits confirmed decision'),msg('b','c','8. ALT approve / reject (+ reason)\nOnly pending account may change'),msg('b','a','9. Saved / stale review: 409',ret=True),msg('b','d','10. Saved decision: queue mail\nafter commit',async_=True)]),
 ('B · NFC attendance and duplicate tap',['Staff browser','NfcAttendanceController','Beneficiary / policy','ActivityAttendance'],[
 msg('a','b','1. Submit tapped UID + activity'),msg('b','c','2. Normalize; authorize; scoped lookup'),msg('c','b','3. Require active + nonarchived\nALT invalid / inaccessible: reject',ret=True),msg('b','d','4. firstOrCreate(activity, beneficiary)'),msg('d','b','5. ALT new: save UTC time + recorder\nELSE duplicate: retain original row',ret=True),msg('b','a','6. JSON 201 new / 200 duplicate\n(or redirect with feedback)',ret=True)]),
 ('C · Assistance release and repeated submission',['Staff browser','Assistance controller','Policy / validation','AssistanceTransaction'],[
 msg('a','b','1. Submit release + submission token'),msg('b','c','2. Authorize active beneficiary; validate'),msg('b','d','3. Find token; create if absent'),msg('d','b','4. New row / existing token\nUnique race: reread existing row',ret=True),msg('b','c','5. ALT repeat: compare all fields\nincluding recorder + UTC release time'),msg('b','a','6. Same: reuse; no duplicate\nDifferent: validation error',ret=True)]),
 ('D · CSV / PDF report export',['Staff browser','Report export controllers','Scoped models','ExportRows / Dompdf'],[
 msg('a','b','1. Request CSV or PDF; type + dates'),msg('b','c','2. Authorize; validate dates; query\ncurrent role / assignment scope'),msg('c','b','3. Attendance or assistance rows',ret=True),msg('b','d','4. ALT CSV: stream safe cells\nELSE PDF <= 1,000: render view'),msg('b','a','5. ALT PDF > 1,000: validation error',ret=True),msg('d','a','6. Download; private, no-store',ret=True)])]
for i,(title,participants,messages) in enumerate(scenarios):
    x=40+(i%2)*1490; y=185+(i//2)*940
    text(r,f'seq-title{i}',title,x,y,1410,45,25)
    for m in messages:
        if m.pop('async_',False): m['async']=True
        if m.pop('ret',False): m['return']=True
    wrapped={'NfcAttendanceController':'NfcAttendanceController<br>store()', 'AssistanceTransaction':'AssistanceTransaction<br>model', 'ExportRows / Dompdf':'ExportRows /<br>Dompdf'}
    spec={'participants':[{'id':chr(97+j),'label':wrapped.get(p,p)} for j,p in enumerate(participants)],'messages':messages}
    old=E.fromstring(seqlayout.layout(spec)).find('.//root')
    for c in old.findall('mxCell'):
        if c.get('style'): c.set('style',c.get('style')+'fontSize=19;fontFamily=Arial;fontColor=#292929;strokeColor=#A50021;')
        if c.get('id') in ['a','b','c','d']:
            g=c.find('mxGeometry');g.set('x',str(float(g.get('x'))-35));g.set('width','260')
    import_cells(r,old,f's{i}-',x,y+40)
    notes=['Invalid / expired / used link or session fails. Approval enables access; rejection keeps it restricted.',
           'USB keystrokes are a documented input assumption. Current endpoint records source=nfc_test.',
           'Each replay repeats authorization. No attendance prerequisite; this records a release, not payment.',
           'Bad dates / unauthorized requests fail. Separate activity CSV uses activity + optional search.'][i]
    text(r,f'seq-note{i}',notes,x,y+845,1400,60,19)

# Reuse completed deployment draft, correcting evidence/status phrasing only.
r=page(4,1600,1050,'Documented Windows LAN topology. This diagram describes the setup; current devices, processes, configuration and service availability were not inspected.')
old=E.parse(BASE/'05-deployment-diagram.drawio').find('.//root')
import_cells(r,old,'dep-',0,0,('title','status','evidence'))
text(r,'deployment-evidence','Evidence: docs/local-lan-mail-queue.md, documented LAN setup and inventory.md. Separate web and queue PHP processes share local persistence.\nDatabase-queued verification and after-commit decision mail use SMTP. USB keyboard input is an assumption; hardware was not tested. No LAN addresses shown.',40,890,1520,110,20)

# Two swimlane processes with deterministic grid placement.
r=page(5,2600,1600,'Lanes identify responsibility. Staff onboarding is separate from beneficiary records. Attendance, assistance and reporting are independent choices; none requires another.')
def lane(ident,label,y,height):
    node(r,ident,label,40,y,2520,height,'swimlane;horizontal=0;startSize=190;container=1;collapsible=0;fillColor=#FFF5DE;fontStyle=1;fontSize=22;')
def step(ident,label,x,y,laneid,w=310,h=85,decision=False):
    node(r,ident,label,x,y,w,h,('rhombus;' if decision else 'rounded=1;')+'fillColor=#FFFFFF;fontSize=20;',laneid)
text(r,'onboard','A · Staff onboarding and approval',40,170,2500,40,26)
lane('applicant','Staff Applicant',220,160);lane('systemA','RLCP system / mail worker',380,180);lane('directorA','Director',560,160)
step('request','Request registration\nemail link',230,35,'applicant')
step('verify','Follow email link;\ncomplete registration',720,35,'applicant')
step('queue','Save request; queue\nverification email',230,45,'systemA')
step('pending','Consume link + session;\ncreate pending User; notify',720,45,'systemA')
step('review','Review pending account;\nconfirm decision',1220,35,'directorA')
step('decision','Approve?',1690,25,'directorA',240,105,True)
step('approve','Approved: enable\nverified staff access',2130,20,'systemA',320,65)
step('reject','Rejected: retain\nrestricted account',2130,105,'systemA',320,65)
for ident,s,t,label,st in [
 ('a1','request','queue','submit',''),('a2','queue','verify','email / link','exitX=1;exitY=0.5;entryX=0;entryY=0.5;'),('a3','verify','pending','valid completion',''),('a4','pending','review','Director notification','exitX=1;exitY=0.5;entryX=0.5;entryY=0;'),('a5','review','decision','review',''),('a6','decision','approve','Yes','exitX=0.5;exitY=0;entryX=0;entryY=0.5;'),('a7','decision','reject','No + reason','exitX=1;exitY=0.5;entryX=0;entryY=0.5;')]:
    points={'a2':[(650,467),(650,297)],'a6':[(1850,432)],'a7':[(2070,637),(2070,517)]}.get(ident,())
    edge(r,ident,s,t,label,st,points)
text(r,'onboard-note','Invalid / expired links or sessions fail. Both decisions store notifications and queue mail after commit. A repeated review returns conflict.',240,735,2300,45,20)
text(r,'program','B · Beneficiary registration, assignment and program operations',40,800,2500,40,26)
lane('directorB','Director',860,150);lane('staff','Director / approved Caseworker',1010,200);lane('systemB','RLCP system',1210,230)
step('register','Register beneficiary\nrecord (not an account)',230,30,'directorB')
step('assign','Assign eligible Caseworker\n(or leave unassigned)',720,30,'directorB')
step('access','Access beneficiary\nDirector: organization\nCaseworker: assigned only',720,40,'staff',340,110)
step('attendance-op','Attendance\nSelect activity; tap card\nor use manual check-in',1250,30,'staff',340,125)
step('assistance-op','Assistance\nEnter release details\nand submission token',1660,30,'staff',340,125)
step('report-op','Reporting\nChoose type / dates;\nview or export CSV/PDF',2070,30,'staff',340,125)
step('attendance-save','Require active record;\nsave once per activity;\nduplicate returns existing',1250,55,'systemB',340,125)
step('assistance-save','Require active record;\nsave once per token;\nchanged replay is rejected',1660,55,'systemB',340,125)
step('report-save','Read scoped history;\nCSV or bounded PDF;\nno business records changed',2070,55,'systemB',340,125)
edge(r,'b1','register','assign','set assignment')
edge(r,'b2','assign','access','save; notify changed caseload')
for i,(target,end) in enumerate([('attendance-op','attendance-save'),('assistance-op','assistance-save'),('report-op','report-save')]):
    cx=40+[1250,1660,2070][i]+170
    edge(r,f'bchoice{i}','access',target,'', 'exitX=1;exitY=0.15;entryX=0.5;entryY=0;',[(1180,1030),(cx,1030)])
    edge(r,f'boperation{i}',target,end,'submit' if i<2 else 'query / export')
text(r,'process-note','Activities may be created / edited by either staff role. Profile editing and NFC linking require access to that beneficiary. Director alone archives / restores.\nNo beneficiary screening step is implemented. Reporting may include empty results. Source: inventory.md AUTH / ASSIGN / ATT / AST / REP and current controllers.',40,1480,2500,80,20)

# Verify XML identity and endpoint integrity before writing the deliverable.
for c in DOC.findall('.//mxCell'):
    if 'swimlane;' in c.get('style',''):
        c.set('style',c.get('style')+'strokeColor=#D4A039;')
    if c.get('id')=='p1-edge-15': c.set('value','dispatches\nafter commit')
assert [d.get('name') for d in DOC.findall('diagram')]==TITLES
for d in DOC.findall('diagram'):
    cells=d.findall('.//mxCell'); ids=[c.get('id') for c in cells]
    assert len(ids)==len(set(ids)),d.get('name')
    for c in cells:
        for key in ('parent','source','target'):
            if c.get(key): assert c.get(key) in ids,(d.get('name'),c.attrib)
        if c.get('edge'): assert c.find('mxGeometry') is not None
E.indent(DOC)
E.ElementTree(DOC).write(BASE/'RLCP-System-Architecture.drawio',encoding='utf-8',xml_declaration=True)
print('Wrote six ordered tabs; unique IDs and all connector endpoints verified.')
