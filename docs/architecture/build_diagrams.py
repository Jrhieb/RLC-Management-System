"""Documentation-only authoring helper. Run one diagram at a time; no application imports."""
from pathlib import Path
import sys
import xml.etree.ElementTree as ET

OUT = Path(__file__).resolve().parent
RED, GOLD, INK, IVORY = '#A50021', '#D4A039', '#252525', '#FFF9EF'

class Drawing:
    def __init__(self, name):
        self.name = name
        self.doc = ET.Element('mxfile', host='app.diagrams.net', type='device')

    def page(self, name, takeaway, rules):
        page = ET.SubElement(self.doc, 'diagram', id='page-'+str(len(self.doc)+1), name=name)
        model = ET.SubElement(page, 'mxGraphModel', dx='1600', dy='1000', grid='1', gridSize='10', page='1', pageScale='1', pageWidth='1600', pageHeight='1000', background='#FFFFFF', math='0', shadow='0')
        self.root = ET.SubElement(model, 'root')
        ET.SubElement(self.root, 'mxCell', id='0')
        ET.SubElement(self.root, 'mxCell', id='1', parent='0')
        self.box('title', name, 40, 25, 1520, 65, 'fillColor='+RED+';fontColor=#FFFFFF;strokeColor='+RED+';fontSize=30;fontStyle=1;')
        self.box('takeaway', takeaway, 40, 100, 1520, 55, 'fillColor=none;strokeColor=none;fontSize=21;align=left;')
        self.box('legend', 'Verified: solid outline | Inferred: dashed outline | Solid arrow: sync | Dashed arrow: queued (labeled)\nRule references resolve to source symbols in inventory.md. '+rules, 40, 880, 1520, 85, 'fillColor='+IVORY+';strokeColor='+GOLD+';fontSize=17;align=left;')

    def box(self, key, text, x, y, w=360, h=130, extra=''):
        style='rounded=0;whiteSpace=wrap;html=0;fillColor=#FFFFFF;strokeColor='+INK+';fontColor='+INK+';fontSize=20;spacing=12;verticalAlign=middle;'+extra
        cell=ET.SubElement(self.root,'mxCell',id=key,value=text,style=style,vertex='1',parent='1')
        ET.SubElement(cell,'mxGeometry',x=str(x),y=str(y),width=str(w),height=str(h),attrib={'as':'geometry'})
        return key

    def edge(self,a,b,label='',dashed=False,extra='',points=None):
        key='edge-'+str(len(self.root))
        style='edgeStyle=orthogonalEdgeStyle;rounded=0;html=0;endArrow=block;endFill=1;strokeColor='+INK+';fontColor='+INK+';fontSize=17;labelBackgroundColor=#FFFFFF;jettySize=25;'+('dashed=1;' if dashed else '')+extra
        cell=ET.SubElement(self.root,'mxCell',id=key,value=label,style=style,edge='1',parent='1',source=a,target=b)
        geometry=ET.SubElement(cell,'mxGeometry',relative='1',attrib={'as':'geometry'})
        if points:
            array=ET.SubElement(geometry,'Array',attrib={'as':'points'})
            for x,y in points:
                ET.SubElement(array,'mxPoint',x=str(x),y=str(y))

    def save(self):
        dest=OUT/(self.name+'.drawio')
        if dest.exists() and '--replace-generated' not in sys.argv:
            raise RuntimeError('Preserve existing drawing: '+dest.name)
        for page in self.doc:
            cells=page.findall('.//mxCell'); ids={c.get('id') for c in cells}
            assert len(ids)==len(cells)
            for cell in cells:
                if cell.get('edge')=='1': assert cell.get('source') in ids and cell.get('target') in ids
        ET.indent(self.doc)
        ET.ElementTree(self.doc).write(dest,encoding='utf-8',xml_declaration=True)
        ET.parse(dest)
        print(dest.name, 'written:',len(self.doc),'named pages; XML and connectors valid')

def package():
    d=Drawing('01-package-diagram')
    d.page('01A | Request and domain packages','Controllers frequently query models directly; policies and visibility scopes are distinct safeguards.','AUTH-01/03/04, NFC-01/02, AST-01, REP-01/02, ACC-01')
    d.box('ui','UI filenames\nBlade layouts, beneficiary/activity/report views\nattendance-workspace.js; app.js',70,190,380,150)
    d.box('routes','Routes + middleware\nweb.php; settings.php\nEnsureStaffAccess / EnsureAccountApproval',610,190,380,150)
    d.box('http','Controllers / Livewire\nBeneficiary, Activity, Attendance, Assistance, Report controllers\nProfile / Security',610,420,380,170)
    d.box('policies','Policies\nBeneficiaryPolicy\nProgramActivityPolicy / UserPolicy',1150,420,380,170)
    d.box('helpers','Helpers / response\nNfcCardUid: normalize, validate\nExportRows: cursor batching\nAttendanceCheckInResponse',70,420,380,170)
    d.box('models','Models + query scopes\nBeneficiary / User / ProgramActivity\nActivityAttendance / AssistanceTransaction\nvisibleTo(user)',610,680,380,155)
    d.box('caseload','Service\nCaseworkerCaseload::summary\ncalled by DashboardController',1150,680,380,155)
    d.edge('ui','routes','requests');d.edge('routes','http','dispatches (sync)');d.edge('http','policies','authorizes');d.edge('http','helpers','uses');d.edge('http','models','queries / writes directly');d.edge('caseload','models','queries assigned rows');d.edge('http','caseload','dashboard summary',extra='exitX=1;exitY=1;entryX=0.5;entryY=0;')
    d.page('01B | Registration, review and messaging packages','Registration verification is explicitly database-queued; account decisions queue mail only after commit.','REG-01/02/03, ACC-01')
    d.box('registration','RegistrationController\nrequestLink / verify / complete',60,200,400,130)
    d.box('email','EmailRegistrationService\nRequest + link/session consumption\nCreates pending User',600,200,400,130)
    d.box('jobs','Jobs (database queue)\nSendRegistrationVerification\nSkipRegistrationVerification',1140,200,400,130)
    d.box('review','AccountRequestController\nReviewCaseworkerAccount::execute\nConditional pending-account update',60,450,400,150)
    d.box('notice','AccountNotificationService\nregistrationCompleted / decisionSaved\nassignmentsChanged\nWrites AccountNotification',600,450,400,150)
    d.box('verifyMail','RegistrationVerificationMail\nWorker -> deliverLink -> Mail::send\nTransport send is synchronous\ninside queued job',1140,450,400,150)
    d.box('models','Models\nRegistrationRequest / User\nAccountNotification\nDirect Eloquent / transaction writes',60,710,400,130)
    d.box('decisionMail','AccountDecisionMail\nShouldQueueAfterCommit\nConfigured queue behavior',600,710,400,130)
    d.box('provider','Documented setup\nMail transport + queue workers\nActive provider / worker availability\nnot verified',1140,710,400,130,'fillColor='+IVORY+';')
    d.edge('registration','email','calls (sync)');d.edge('email','jobs','dispatches after commit',True)
    d.edge('jobs','verifyMail','worker delivers')
    d.edge('email','notice','registrationCompleted (sync)')
    d.edge('review','notice','decisionSaved (sync)');d.edge('notice','decisionMail','queues after commit',True)
    d.edge('review','models','conditional write')
    d.edge('notice','models','stores notification',extra='exitX=0;exitY=1;entryX=1;entryY=0;')
    d.save();return d

def usecase():
    d=Drawing('02-use-case-diagram')
    groups=[('02A | Applicants and restricted staff','Registration creates a pending Caseworker; beneficiary records never become login accounts.','REG-01/02/03, AUTH-02, ACC-01',[
        ('Guest / applicant',['View public home','Request email link\n[GUEST]','Verify link and complete registration\n[GUEST; valid registration session]']),
        ('Pending / rejected\nCaseworker',['View account status\n[OWN ACCOUNT]','Read account notifications\n[OWN ACCOUNT]','Manage permitted security / verification\n[OWN; route middleware applies]'])]),
        ('02B | Director use cases','Directors manage organization records and review staff access; account creation by a Director is disabled.','AUTH-01/03/04, REG-03, ASSIGN-01, NFC-01/02, AST-01, REP-01, ACC-01',[
        ('Verified Director',['Review account requests\n[ORG; pending Caseworker]','Create / assign beneficiaries\n[ORG; eligible assignee or unassign]','Archive / restore beneficiaries\n[ORG; state checks]']),
        ('Verified Director\n(same actor)',['View/edit profiles; link cards; record assistance\n[ORG; active required for release]','Manage activities and attendance\n[ORG; active beneficiary for check-in]','Reports / exports; dashboard; own settings\n[ORG data; OWN account settings]'])]),
        ('02C | Approved Caseworker use cases','Activities are organization-wide; beneficiary records and historical rows follow current assignment.','AUTH-01/03/04, NFC-01/02, ATT-01, AST-01, REP-01, ACC-01',[
        ('Approved verified\nCaseworker',['View caseload / profile / history\n[ASSIGNED; nonarchived]','Edit beneficiary; link/replace/unlink NFC\n[ASSIGNED; nonarchived]','Record assistance release\n[ASSIGNED; active]']),
        ('Approved verified\nCaseworker (same actor)',['Create/edit/view activities\n[ORG activity definitions]','Check in attendance / NFC lookup\n[ASSIGNED; active beneficiary]','Dashboard / reports / exports; settings\n[ASSIGNED data; OWN settings]'])])]
    for title,take,rules,actors in groups:
        d.page(title,take,rules)
        d.box('boundary','RLCP application boundary',420,180,1130,650,'verticalAlign=top;align=left;fontStyle=1;fillColor='+IVORY+';strokeColor='+RED+';')
        for row,(actor,uses) in enumerate(actors):
            y=280+row*310
            d.box('actor'+str(row),actor,70,y+65,280,110,'shape=umlActor;verticalLabelPosition=bottom;verticalAlign=top;fillColor=#FFFFFF;')
            for col,text in enumerate(uses):
                key=f'u{row}{col}';d.box(key,text,470+col*355,y,320,150,'ellipse;shape=ellipse;fillColor=#FFFFFF;')
                d.edge('actor'+str(row),key,'',extra='endArrow=none;exitX=1;exitY=0.5;entryX=0.5;entryY=0;',points=[(380+col*12,y+120),(380+col*12,y-25-col*20),(630+col*355,y-25-col*20)])
    d.save();return d

def data():
    d=Drawing('03-domain-data-diagram')
    d.page('03A | Program records and relationships','Beneficiary is a managed record; User is an authenticated account with optional caseload assignments.','AUTH-03, NFC-01/02, ATT-01, AST-01; migrations in inventory section 5')
    d.box('user','User / users\nPK id: bigint\nemail: string UNIQUE\nrole: string; approval_status: enum\nemail_verified_at: timestamp?',60,190,400,180)
    d.box('ben','Beneficiary / beneficiaries\nPK id; beneficiary_id: string UNIQUE\ncaseworker_id: FK User?\nnfc_uid: string(64)? UNIQUE\nstatus: string; deleted_at: timestamp?',600,190,420,180)
    d.box('activity','ProgramActivity / program_activities\nPK id; title: string\nactivity_date: date; start_time: time?\ncreated_by: FK User?\ndevelopment_area: string(30)',600,640,420,190)
    d.box('attendance','ActivityAttendance / activity_attendances\nPK id; beneficiary_id: FK\nprogram_activity_id: FK\nrecorded_by: FK User?\nchecked_in_at: timestamp; source: string\nUNIQUE(activity, beneficiary)',1120,420,420,210)
    d.box('assist','AssistanceTransaction\nassistance_transactions\nPK id; beneficiary_id: FK\nrecorded_by: FK User?\nsubmission_token: UUID? UNIQUE\nquantity: uint; released_at: datetime',60,420,420,210)
    d.edge('ben','user','0..many assigned / 0..1 user');d.edge('assist','ben','many / 1 beneficiary');d.edge('attendance','ben','many / 1 beneficiary');d.edge('attendance','activity','many / 1 activity');d.edge('assist','user','many / 0..1 recorder')
    d.box('note','Verified: creator and recorder FKs also point to User (omitted repeated arrows for clarity).\nBeneficiary/activity deletion restricted by attendance; beneficiary deletion restricted by assistance.\nUser references null on delete. Soft archive retains history. All fields are schema names only.',60,680,440,150,'fillColor='+IVORY+';fontSize=18;')
    d.page('03B | Accounts, registration and support tables','Registration requests match email in application logic; there is no registration-request-to-user foreign key.','REG-01/02/03, ACC-01; migrations in inventory section 5')
    d.box('user','users\nPK id; email UNIQUE\napproval_reviewed_by: FK users?\napproval_reviewed_at: timestamp?\nrejection_reason: text?\n2FA fields nullable',620,190,390,200)
    d.box('registration','registration_requests\nPK id; email UNIQUE\ntoken_hash: char(64)? UNIQUE\nsession_token_hash: char(64)? UNIQUE\nexpiry / verification / completion timestamps\nNo User FK',70,190,420,210)
    d.box('notifications','account_notifications\nPK id: UUID\nrecipient_id / account_id: FK users\nkind; event_key; read_at?\nadded_count / removed_count\nUNIQUE(recipient_id, event_key)',1110,190,420,210)
    d.box('passkeys','passkeys\nPK id; user_id: FK users\ncredential_id UNIQUE\ncredential: JSON\nlast_used_at: timestamp?',620,580,390,190)
    d.edge('notifications','user','many / 1 each; cascade delete');d.edge('passkeys','user','many / 1; cascade delete')
    d.box('infra','Support schema (Verified migrations)\njobs / job_batches / failed_jobs\ncache / cache_locks\nsessions / password_reset_tokens\nNo runtime contents inspected',70,580,420,190,'fillColor='+IVORY+';')
    d.box('note','Verified application relationships\nReviewing user: self-FK, nullable\nSessions user_id: index, NOT declared FK\nUser model exposes assignedBeneficiaries\nNotification scope filters recipient + kind',1110,580,420,190,'fillColor='+IVORY+';fontSize=18;')
    d.save();return d

def flow(d,title,take,rules,nodes,edges,notes=''):
    d.page(title,take,rules)
    positions=[(60,200),(620,200),(1180,200),(60,460),(620,460),(1180,460)]
    for i,(key,label) in enumerate(nodes):
        x,y=positions[i];d.box(key,label,x,y,350,170)
    for a,b,label,*rest in edges:d.edge(a,b,label,bool(rest and rest[0]))
    if notes:d.box('notes',notes,60,740,1470,105,'fillColor='+IVORY+';strokeColor='+GOLD+';align=left;fontSize=19;')

def registration():
    d=Drawing('04-registration-approval-flow')
    flow(d,'04A | Email-first registration','A one-use email link becomes a short-lived registration session before a pending account is created.','REG-01/02',[
        ('entry','Guest POST /register\nRegistrationController::requestLink\nValidate normalized email'),
        ('request','EmailRegistrationService::requestLink\nExisting email: no-op job\nOtherwise upsert request + hash\nInside transaction'),
        ('queue','Database queue\nSendRegistrationVerification\nor SkipRegistrationVerification\nAfter commit; single attempt'),
        ('complete','POST /register/complete\nAtomically consume session\nCreate verified pending Caseworker\nNotify Directors; await approval'),
        ('verify','GET /register/verify/{token}\nAtomic link consumption\nCreate session hash + expiry\nRedirect to completion form'),
        ('mail','Job calls deliverLink\nReject obsolete generation\nClaim cache generation once\nSend RegistrationVerificationMail')],
        [('entry','request','sync'),('request','queue','dispatch after commit',True),('queue','mail','worker invokes'),('mail','verify','applicant follows link'),('verify','complete','valid session')],
        'Failure branches: invalid/expired/used link or session -> registration error. Duplicate email -> validation / unique constraint. Queue or delivery failure -> invalidate link; sanitized failure. No real tokens are documented.')
    flow(d,'04B | Director review and decision delivery','The committed account decision survives mail enqueue failure and cannot be applied twice.','REG-03, AUTH-01/02, ACC-01',[
        ('director','Director POST account review\nAccountRequestController::review\nUserPolicy::reviewAccountRequests'),
        ('action','ReviewCaseworkerAccount::execute\nValidate decision + confirmation\nReject requires reason'),
        ('db','Transaction: conditional update\nOnly pending Caseworker\nStore reviewer + review timestamp'),
        ('result','Controller response\nChanged: success\nNo eligible row: conflict 409\nAccount access follows status'),
        ('notice','AccountNotificationService\ndecisionSaved\nStore recipient notification\nUnique event avoids duplicate'),
        ('mail','AccountDecisionMail\nQueue after commit\nConfigured queue behavior\nEnqueue failure does not undo review')],
        [('director','action','sync'),('action','db','conditional write'),('db','notice','same transaction'),('notice','mail','queue after commit',True),('notice','result','return decision result')],
        'Source packages: app/Actions/ReviewCaseworkerAccount.php; app/Services/AccountNotificationService.php; app/Mail/AccountDecisionMail.php. No Director-created account route; rejected accounts remain restricted.')
    d.save();return d

def attendance():
    d=Drawing('05-attendance-flow')
    flow(d,'05A | NFC test-UID attendance','Eligibility, current assignment and duplicate protection are checked before returning attendance feedback.','AUTH-01/03/04, NFC-02, ATT-01',[
        ('entry','POST activity attendance/nfc\nNfcAttendanceController::store\nStaff middleware + activity policy'),
        ('format','NfcCardUid\nNormalize case/separators\nValidate supported UID format'),
        ('lookup','Transaction: beneficiary lookup\nwithTrashed + visibleTo(user)\nLock matched row\nNo match -> validation error'),
        ('result','AttendanceCheckInResponse::make\nCreated: JSON 201\nDuplicate: JSON 200\nOr redirect with feedback'),
        ('insert','ActivityAttendance::firstOrCreate\nUNIQUE(activity, beneficiary)\nUTC time + recorder\nsource = nfc_test'),
        ('check','Require active, nonarchived\nBeneficiaryPolicy::view\nInactive / archived -> error\nUnauthorized -> deny')],
        [('entry','format','validate'),('format','lookup','valid'),('lookup','check','found'),('check','insert','eligible'),('insert','result','created / existing')],
        'Verified manual alternative: ActivityAttendanceController::store uses beneficiary identifier + visibleTo, active check, lock and insertOrIgnore. Both paths return existing attendance instead of inserting a duplicate. No checkout route found.')
    flow(d,'05B | NFC ownership and staff lookup','Card ownership is unique even for archived beneficiaries; a stale replacement cannot overwrite a newer card.','NFC-01, AUTH-03',[
        ('entry','PUT beneficiary NFC\nBeneficiaryNfcController::update\nUpdate policy + normalized UID'),
        ('unique','Validate table-level uniqueness\nIncludes archived beneficiaries\nConflict -> ownership error'),
        ('lock','Lock current beneficiary\nReauthorize update\nSame UID -> already_registered'),
        ('result','Save registered UID\nDB uniqueness race -> validation\ncheck_only -> ready, no write\nDELETE path clears UID'),
        ('confirm','Different existing card?\nRequire confirmation and expected UID\nStale / missing -> replacement_required\nJSON 409 or validation'),
        ('lookup','Separate staff POST /nfc/lookup\nNormalize + visibleTo + active\nView policy -> profile redirect\nNo match -> validation error')],
        [('entry','unique','validate'),('unique','lock','available'),('lock','confirm','replacement check'),('confirm','result','confirmed / new card')],
        'No public card-verification route exists. Staff lookup is read-only. USB hardware is not a human actor; physical device integration was not exercised.')
    d.box('device','Inferred supporting input device: USB reader may provide keyboard-style UID input; no hardware integration claim.',60,660,1470,60,'dashed=1;fillColor='+IVORY+';fontSize=18;')
    d.save();return d

def assistance():
    d=Drawing('06-assistance-report-flow')
    flow(d,'06A | Assistance release and idempotency','A token replay is accepted only when every release field, including recorder, matches the original.','AUTH-03, AST-01',[
        ('entry','GET form / POST assistance\nAssistanceTransactionController\nBeneficiary update policy\nActive status required'),
        ('validate','Validate UUID + release fields\nCategory and quantity limits\nManila release time -> UTC\nLowercase submission token'),
        ('find','Find by submission_token\nAbsent: create record directly\nMatching unique race: reread\nOther DB failure: rethrow'),
        ('result','Redirect beneficiary profile\nNew: release recorded\nReplay: no duplicate created'),
        ('compare','New record: proceed\nExisting token: compare all fields\nSame payload -> accepted replay\nDifferent payload -> validation error\nUnsaved record -> server failure'),
        ('data','AssistanceTransaction\nUnique nullable token in schema\nRequired UUID in request\nBeneficiary and recorder relations')],
        [('entry','validate','authorized'),('validate','find','valid'),('find','data','read / create'),('find','compare','new or existing'),('compare','result','new / exact replay')],
        'This records assistance already released; no payment gateway or stock ledger was found. Token creation in the UI is not asserted because view/JS contents were not opened. No application actions were executed.')
    flow(d,'06B | Reports and exports','Reports read scoped history; exports preserve the same access boundary and never write business records.','REP-01/02, AUTH-03',[
        ('entry','GET reports / CSV / PDF\nReport*Controller\nStaff + viewAny policies\nActivity export uses activity view'),
        ('dates','Validate date bounds\nDefault current Manila month\nEnd before start -> validation\nConvert to UTC half-open range'),
        ('query','Attendance / Assistance queries\nvisibleTo(user), ordered history\nDirector includes archived history\nCaseworker current assignment only'),
        ('html','ReportController::index\nCounts + separate pagination\nRead-only report view'),
        ('csv','ReportExportController\nExportRows::descending batches\nFormula-safe CSV streaming\nActivity CSV also supports search'),
        ('pdf','ReportPdfExportController\nOver 1000 rows -> validation\nOtherwise reports.pdf + Dompdf\nA4 landscape, remote/PHP disabled')],
        [('entry','dates','report range'),('dates','query','valid range'),('query','pdf','PDF'),('query','csv','CSV'),('dates','html','scoped queries / HTML')],
        'Activity-specific CSV follows its activity + search filter rather than the report date-range branch. HTML/CSV/PDF may contain no rows. CSV/PDF responses are private/no-store. Runtime export behavior was not exercised.')
    d.save();return d

if __name__=='__main__':
    {'01':package,'02':usecase,'03':data,'04':registration,'05':attendance,'06':assistance}[sys.argv[1]]()
