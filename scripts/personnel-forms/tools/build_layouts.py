# Writes resources/forms/<form>/<version>/{template.pdf, layout.json, manifest.json} from the cleaned templates.
# Positions were measured from the official forms (top-left points). Re-run after any layout change so the manifest
# checksums match; the renderer refuses a template or layout whose checksum differs.
# Usage: python -I build_layouts.py <dir with cleaned templates> <repo root>
import json, hashlib, shutil, sys, os
src, repo = sys.argv[1], sys.argv[2]
T = lambda key,label,page,x,b,w,size=10: {"key":key,"label":label,"page":page,"type":"text","x":x,"baseline":b,"width":w,"size":size}
M = lambda key,page,x,b,box=None: {"key":key,"page":page,"type":"mark","x":x,"baseline":b,"size":11, **({"box":box} if box else {})}
L = lambda key,label,page,lines,size=10: {"key":key,"label":label,"page":page,"type":"lines","size":size,"lines":[{"x":x,"baseline":b,"width":w} for x,b,w in lines]}
I = lambda key,page,x,bottom,w,h: {"key":key,"page":page,"type":"image","x":x,"bottom":bottom,"width":w,"height":h}
forms = {}
forms["vehicle-accident-report/2000-02-08"] = ("vehicle_accident_report", "City of Miami Beach Vehicle Accident Report", [612,792], 2, [
 T("date_of_report","Date of report",1,126,126.5,170),
 T("driver_years_with_city","Length of time driver with the City (years)",1,316,148.5,86),
 T("vehicle_type_experience_years","Experience with this type of vehicle (years)",1,316,170.5,86),
 M("injury_notice_yes",1,463,192), M("injury_notice_no",1,525,192),
 T("hours_on_duty","Total hours on duty at time of accident",1,250,213.5,183),
 M("vehicle_condition_form_yes",1,326,234.5), M("vehicle_condition_form_no",1,436,234.5),
 M("reasonable_observation_form_yes",1,332,256.5), M("reasonable_observation_form_no",1,436,256.5),
 M("cdl_drug_test_yes",1,331,278), M("cdl_drug_test_no",1,436,278),
 M("excessive_hours_yes",1,169,320), M("excessive_hours_no",1,224,320),
 M("speeding_yes",1,342,320.5), M("speeding_no",1,392,320.5),
 M("sickness_yes",1,500,321), M("sickness_no",1,552,321),
 M("physical_defect_yes",1,169,341.5), M("physical_defect_no",1,224,341.5),
 M("alcohol_yes",1,342,342), M("alcohol_no",1,392,342),
 M("drugs_yes",1,502,342.5), M("drugs_no",1,552,342.5),
 T("accidents_past_18_months","Number of accidents in the past 18 months",1,246,363.5,105),
 L("other_factors","Other contributing factors (explain)",1,[(110,385.5,462),(33,407,542),(33,428.5,542)]),
 L("supervisor_comments","Supervisor comments",1,[(88,470.5,485),(33,492,541),(33,514,541),(33,535,541),(33,556.5,541)]),
 T("supervisor_date","Supervisor date",1,74,618.5,80),
 I("supervisor_signature",1,340,620,152,19),
 T("department_head_date","Department head date",1,57,640.5,80),
 T("department_head_name","Department head",1,338,642,156),
 L("supervisor_esign_note","Supervisor electronic signature record",1,[(300,700,280),(300,709,280),(300,718,280)],7),
 T("employee_name","Employee's name",2,140,188.5,415),
 T("dept_div","Dept./Div.",2,99,210.5,178), T("job_classification","Job classification",2,379,210.5,185),
 T("city_vehicle_number","City vehicle #",2,121,231.5,140), T("vehicle_type","Type of vehicle",2,357,231.5,206),
 T("make_and_license","Make & license tag of vehicle",2,206,253,358),
 T("accident_date","Date of accident",2,132,312.5,158), T("accident_time","Time",2,334,312.5,230),
 T("location","Location of accident",2,152,334,412),
 T("passengers","List of passengers",2,141,355.5,424),
 T("other_party_name","Name of other party involved",2,192,377.5,370),
 T("other_party_address","Address of other party",2,158,398.5,220), T("other_party_phone","Other party phone",2,423,398.5,140),
 T("other_insurance","Name of other insurance company / agent",2,218,420,346),
 L("injuries","Was anyone injured? Explain",2,[(191,441.5,362),(46,462.5,518)]),
 T("other_vehicle_year","Other vehicle year",2,143,484.5,110), T("other_vehicle_make_tag","Other vehicle make & tag #",2,335,484.5,227),
 M("supervisor_notified_yes",2,331,505.5), M("supervisor_notified_no",2,486,505.5),
 T("police_on_scene","Were police on the scene?",2,176,527,38), T("fl_report_made","Was FL traffic accident report made?",2,413,527,37), T("photos_taken","Photos taken?",2,526,527,37),
 T("person_charged","Person charged by police",2,171,548,143), T("charge","Charge",2,360,548,202),
 T("witness1_name","Witness 1 name",2,132,569.5,98), T("witness1_address","Witness 1 address",2,279,569.5,124), T("witness1_phone","Witness 1 phone",2,446,569.5,115),
 T("witness2_name","Witness 2 name",2,132,590.5,98), T("witness2_address","Witness 2 address",2,279,590.5,124), T("witness2_phone","Witness 2 phone",2,446,590.5,115),
 T("damage_other","Damage to other vehicle / property",2,213,612,348),
 L("driver_description","Driver's description of accident",2,[(202,633.5,360),(46,654.5,517),(46,675.5,517),(46,697,517),(46,718.5,517)]),
 T("driver_date","Driver date",2,74,739.5,143),
 I("driver_signature",2,381,740,175,19),
 L("driver_esign_note","Driver electronic signature record",2,[(46,776,520),(46,784.5,520)],6.5),
])
forms["supervisors-report-of-accident/1.0"] = ("supervisors_report_of_accident", "Supervisor's Report of Accident", [612,792], 1, [
 T("employee_name","Employee's name",1,137,104,167), T("employee_id","ID / clock no.",1,407,103,172),
 T("division","Division",1,137,121.5,167), T("department","Department",1,406,121.5,172),
 T("age","Age",1,137,139.5,167), T("classification","Classification",1,406,139.5,173),
 T("accident_date","Date",1,74,199,68), T("accident_time","Time",1,191,199,95), T("place","Place",1,345,199,233),
 T("first_reported_to","Accident first reported to",1,190,219,388),
 T("witnesses","Witnesses",1,112,240,466),
 L("description","Describe how accident occurred",1,[(230,260,349),(33,280.5,546),(33,300.5,546),(33,320,546)]),
 T("type_of_injury","Type of injury",1,123,340,181), T("part_of_body","Part of body",1,392,341,187),
 T("medical_treatment_by","Medical treatment by",1,163,361,415),
 M("losing_time_yes",1,265,380,[260,366,278,384]), M("losing_time_no",1,337,380,[332,366,350,384]),
 M("cause_inadequate_supervision",1,46,420,[41,406,59,424]), M("cause_carelessness",1,323,418,[318,404,336,422]),
 M("cause_improper_instructions",1,47,442,[42,428,60,446]), M("cause_poor_judgement",1,323,440,[318,426,336,444]),
 M("cause_failed_to_follow_orders",1,47,462,[42,448,60,466]), M("cause_failure_to_use_safety_equipment",1,323,461,[318,447,336,465]),
 M("cause_unsuited_for_position",1,47,482,[42,468,60,486]), M("cause_dangerous_condition",1,322,481,[317,467,335,485]),
 M("cause_poor_work_attitude",1,47,502,[42,488,60,506]), M("cause_defective_equipment",1,322,502,[317,488,335,506]),
 M("cause_another_party",1,48,522,[43,508,61,526]), T("cause_another_party_explain","Caused by another party (explain)",1,267,523,313),
 M("cause_other",1,47,542,[42,528,60,546]), T("cause_other_explain","Other cause (explain)",1,164,542,414),
 M("action_instructed_employee",1,47,582,[42,568,60,586]), M("action_replaced_repaired_equipment",1,322,582,[317,568,335,586]),
 M("action_training",1,46,603,[41,589,59,607]), M("action_changed_job_duties",1,321,602,[316,588,334,606]),
 M("action_corrected_hazard",1,47,623,[42,609,60,627]), M("action_other",1,321,622,[316,608,334,626]), T("action_other_explain","Other action (explain)",1,433,623,146),
 M("similar_accidents_yes",1,282,660,[277,646,295,664]), M("similar_accidents_no",1,352,661,[347,647,365,665]),
 T("similar_accidents_explain","Similar accidents (explain)",1,96,681,484),
 T("signed_date","Date",1,33,718,136), I("supervisor_signature",1,188,720,214,18), T("supervisor_title","Title",1,420,718,160),
 L("supervisor_esign_note","Supervisor electronic signature record",1,[(33,780,546),(33,788,546)],6.5),
])
forms["interoffice-memorandum/1.0"] = ("memo_form", "Miami Beach Fire Department Interoffice Memorandum", [612,792], 1, [
 T("to","To",1,97,129,249,11), T("date","Date",1,388,129,147,11), T("from","From",1,109,156,237,11),
 T("subject","Subject",1,126,180,408,11),
 {"key":"body","label":"Memorandum","page":1,"type":"box","x":76,"top":200,"width":459,"height":484,"size":11,"leading":14.5,"min_size":11},
])
for rel,(srcname,title,size,pages,fields) in forms.items():
    d=os.path.join(repo,"resources","forms",*rel.split("/")); os.makedirs(d,exist_ok=True)
    shutil.copyfile(f"{src}/{srcname}.pdf", f"{d}/template.pdf")
    layout={"schema":"mbfd-overlay-layout","schema_version":"1.0","coordinate_origin":"top-left points","min_size":6.5,"fields":fields}
    lb=(json.dumps(layout,indent=1)+"\n").encode()
    open(f"{d}/layout.json","wb").write(lb)
    tb=open(f"{d}/template.pdf","rb").read()
    man={"form_key":os.path.basename(os.path.dirname(d)) ,"display_name":title,"template_sha256":hashlib.sha256(tb).hexdigest(),"layout_sha256":hashlib.sha256(lb).hexdigest(),"expected_page_count":pages,"expected_page_sizes":[size]*pages,"renderer_contract_version":"1.0","template_source":"Official City/MBFD PDF supplied 2026-11-05; unusable AcroForm widgets removed, page content unchanged."}
    open(f"{d}/manifest.json","w").write(json.dumps(man,indent=2)+"\n")
    print(rel, len(fields), man["template_sha256"][:12])

