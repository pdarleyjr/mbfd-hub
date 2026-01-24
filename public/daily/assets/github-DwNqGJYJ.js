import{c as y,b as D,n as h,L as f,D as $,A as E}from"./useApparatusStatus-D3iYQgib.js";import{r as S,j as w,_ as T}from"./index-CTqLvJ19.js";const x=[["path",{d:"M8 2v4",key:"1cmpym"}],["path",{d:"M16 2v4",key:"4m81vk"}],["rect",{width:"18",height:"18",x:"3",y:"4",rx:"2",key:"1hopcy"}],["path",{d:"M3 10h18",key:"8toen8"}]],j=y("calendar",x);const A=[["path",{d:"M20 6 9 17l-5-5",key:"1gmf2c"}]],N=y("check",A);const P=[["path",{d:"M21.801 10A10 10 0 1 1 17 3.335",key:"yps3ct"}],["path",{d:"m9 11 3 3L22 4",key:"1pflzl"}]],R=y("circle-check-big",P);const F=[["path",{d:"m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3",key:"wmoenq"}],["path",{d:"M12 9v4",key:"juzpu7"}],["path",{d:"M12 17h.01",key:"p32p05"}]],U=y("triangle-alert",F);const M=[["path",{d:"M12 3v12",key:"1x0j5s"}],["path",{d:"m17 8-5-5-5 5",key:"7q97r8"}],["path",{d:"M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4",key:"ih7n3h"}]],O=y("upload",M);const v=[["path",{d:"M18 6 6 18",key:"1bl5f8"}],["path",{d:"m6 6 12 12",key:"d8bk6v"}]],_=y("x",v),H=({isOpen:g,onClose:e,title:t,children:s,className:o})=>(S.useEffect(()=>(g?document.body.style.overflow="hidden":document.body.style.overflow="unset",()=>{document.body.style.overflow="unset"}),[g]),g?w.jsxs("div",{className:"fixed inset-0 z-50 flex items-center justify-center",children:[w.jsx("div",{className:"absolute inset-0 bg-black/50 backdrop-blur-sm",onClick:e}),w.jsxs("div",{className:D("relative bg-white rounded-2xl shadow-2xl max-w-md w-full mx-4 max-h-[90vh] overflow-y-auto",o),children:[w.jsxs("div",{className:"flex items-center justify-between px-6 py-4 border-b border-gray-200",children:[w.jsx("h2",{className:"text-xl font-semibold text-gray-900",children:t}),w.jsx("button",{onClick:e,className:"p-1 hover:bg-gray-100 rounded-lg transition-colors",children:w.jsx(_,{className:"w-5 h-5 text-gray-500"})})]}),w.jsx("div",{className:"px-6 py-4",children:s})]})]}):null);class I{adminPassword=null;constructor(){}setAdminPassword(e){this.adminPassword=e}clearAdminPassword(){this.adminPassword=null}isAdminAuthenticated(){return this.adminPassword!==null}getHeaders(e=!1){const t={"Content-Type":"application/json"};return e&&this.adminPassword&&(t["X-Admin-Password"]=this.adminPassword),t}async checkExistingDefects(e){try{const t=await fetch(`${h}/issues?state=open&labels=${f.DEFECT},${encodeURIComponent(e)}&per_page=100`,{method:"GET",headers:this.getHeaders()});if(!t.ok)return console.warn(`Failed to fetch defects: ${t.statusText}`),new Map;const s=await t.json(),o=Array.isArray(s)?s:[],c=new Map;for(const l of o){const r=l.title.match($);if(r){const[,,n,a]=r,i=`${n}:${a}`;c.set(i,l)}}return c}catch(t){return console.error("Error fetching existing defects:",t),new Map}}async submitChecklist(e){const{user:t,apparatus:s,date:o,defects:c}=e,l=await this.checkExistingDefects(s);let r=0;const n=[];for(const a of c)try{const i=`${a.compartment}:${a.item}`,d=l.get(i);d?await this.addCommentToDefect(d.number,t.name,t.rank,o,a.notes,a.photoUrl):await this.createDefectIssue(s,a.compartment,a.item,a.status,a.notes,t.name,t.rank,o,a.photoUrl)}catch(i){r++;const d=`${a.compartment}:${a.item}`;n.push(d),console.error(`Failed to process defect ${d}:`,i)}if(r>0)throw new Error(`Failed to submit ${r} defect(s): ${n.join(", ")}. Please try again.`);if(await this.createLogEntry(e),c.length>0)try{await this.createSupplyTasksForDefects(c,s)}catch(a){console.error("Failed to create supply tasks (non-fatal):",a)}}async createDefectIssue(e,t,s,o,c,l,r,n,a){const i=`[${e}] ${t}: ${s} - ${o==="missing"?"Missing":"Damaged"}`;let d=`
## Defect Report

**Apparatus:** ${e}
**Compartment:** ${t}
**Item:** ${s}
**Status:** ${o==="missing"?"❌ Missing":"⚠️ Damaged"}
**Reported By:** ${l} (${r})
**Date:** ${n}

### Notes
${c}
`;a&&(d+=`
### Photo Evidence

![Defect Photo](${a})
`),d+=`
---
*This issue was automatically created by the MBFD Checkout System.*`,d=d.trim();const u=[f.DEFECT,e];o==="damaged"&&u.push(f.DAMAGED);try{const m=await fetch(`${h}/issues`,{method:"POST",headers:this.getHeaders(),body:JSON.stringify({title:i,body:d,labels:u})});if(!m.ok)throw new Error(`Failed to create issue: ${m.statusText}`)}catch(m){throw console.error("Error creating defect issue:",m),m}}async addCommentToDefect(e,t,s,o,c,l){let r=`
### Verification Update

**Verified still present by:** ${t} (${s})
**Date:** ${o}

${c?`**Additional Notes:** ${c}`:""}
`;l&&(r+=`
### Photo Evidence

![Defect Photo](${l})
`),r+=`
---
*This comment was automatically added by the MBFD Checkout System.*`,r=r.trim();try{const n=await fetch(`${h}/issues/${e}/comments`,{method:"POST",headers:this.getHeaders(),body:JSON.stringify({body:r})});if(!n.ok)throw new Error(`Failed to add comment: ${n.statusText}`)}catch(n){throw console.error("Error adding comment to issue:",n),n}}async createLogEntry(e){const{user:t,apparatus:s,date:o,items:c}=e,l=`[${s}] Daily Inspection - ${o}`;let r=null,n="";try{const{buildReceiptPayloadFromInspection:i,createHostedReceipt:d,buildReceiptMarkdown:u}=await T(async()=>{const{buildReceiptPayloadFromInspection:p,createHostedReceipt:k,buildReceiptMarkdown:b}=await import("./receipt-nVCul5S8.js");return{buildReceiptPayloadFromInspection:p,createHostedReceipt:k,buildReceiptMarkdown:b}},[]),m=i(e);try{r=(await d(h,m,this.adminPassword||void 0)).url,console.log(`Created hosted receipt: ${r}`)}catch(p){console.error("Failed to create hosted receipt, using fallback:",p),n=u(m)}}catch(i){console.error("Receipt module import failed:",i)}let a=`
## Daily Inspection Log

**Apparatus:** ${s}
**Conducted By:** ${t.name} (${t.rank})
**Date:** ${o}

### Summary
- **Total Items Checked:** ${c.length}
- **Issues Found:** ${e.defects.length}

${e.defects.length>0?`
### Issues Reported
${e.defects.map(i=>`- ${i.compartment}: ${i.item} - ${i.status==="missing"?"❌ Missing":"⚠️ Damaged"}`).join(`
`)}`:"✅ All items present and working"}
`;r?a+=`

---

📋 **[View Full Printable Receipt](${r})**

_This receipt contains the complete inspection details in a print-friendly format._
`:n&&(a+=`

---

${n}
`),a+=`
---
*This inspection log was automatically created by the MBFD Checkout System.*`,a=a.trim();try{const i=await fetch(`${h}/issues`,{method:"POST",headers:this.getHeaders(),body:JSON.stringify({title:l,body:a,labels:[f.LOG,s]})});if(!i.ok)throw new Error(`Failed to create log: ${i.statusText}`);const d=await i.json(),u=await fetch(`${h}/issues/${d.number}`,{method:"PATCH",headers:this.getHeaders(),body:JSON.stringify({state:"closed"})});if(u.ok)console.log(`Successfully created and closed log issue #${d.number}`);else{const m=await u.text();let p;try{p=JSON.parse(m)}catch{p={message:m}}console.error(`Failed to close log issue #${d.number}:`,{status:u.status,statusText:u.statusText,error:p,message:p.message||"Unknown error"}),console.warn(`Log entry created as issue #${d.number} but could not be closed. It may require manual closure or token permissions review.`)}}catch(i){throw console.error("Error creating log entry:",i),i}}async getAllDefects(){try{const e=await fetch(`${h}/issues?state=open&labels=${f.DEFECT}&per_page=100`,{method:"GET",headers:this.getHeaders(!0)});if(!e.ok)throw e.status===401?new Error("Unauthorized. Please enter the admin password."):new Error(`Failed to fetch defects: ${e.statusText}`);const t=await e.json();return(Array.isArray(t)?t:[]).map(o=>this.parseDefectFromIssue(o))}catch(e){throw console.error("Error fetching all defects:",e),e}}parseDefectFromIssue(e){const t=e.title.match($);let s="Rescue 1",o="Unknown",c="Unknown",l="missing";t&&(s=t[1],o=t[2],c=t[3],l=t[4].toLowerCase());let r;if(e.body){const n=e.body.match(/!\[.*?\]\((https?:\/\/[^\)]+)\)/);n&&(r=n[1])}return{issueNumber:e.number,apparatus:s,compartment:o,item:c,status:l,notes:e.body||"",reportedBy:e.user?.login||"Unknown",reportedAt:e.created_at,updatedAt:e.updated_at,resolved:!1,photoUrl:r}}async resolveDefect(e,t,s){try{const o=await fetch(`${h}/issues/${e}`,{method:"GET",headers:this.getHeaders(!0)});if(!o.ok)throw new Error(`Failed to fetch issue details: ${o.statusText}`);const r=(await o.json()).labels.map(i=>i.name).find(i=>E.includes(i)),n=[f.DEFECT,f.RESOLVED];r&&n.push(r),await fetch(`${h}/issues/${e}/comments`,{method:"POST",headers:this.getHeaders(!0),body:JSON.stringify({body:`
## ✅ Defect Resolved

**Resolved By:** ${s}
**Date:** ${new Date().toISOString()}

### Resolution
${t}

---
*This defect was marked as resolved via the MBFD Admin Dashboard.*
`.trim()})});const a=await fetch(`${h}/issues/${e}`,{method:"PATCH",headers:this.getHeaders(!0),body:JSON.stringify({state:"closed",labels:n})});if(!a.ok)throw a.status===401?new Error("Unauthorized. Please enter the admin password."):new Error(`Failed to resolve defect: ${a.statusText}`)}catch(o){throw console.error("Error resolving defect:",o),o}}async getFleetStatus(){const e=await this.getAllDefects();return this.computeFleetStatus(e)}computeFleetStatus(e){const t=new Map;for(const s of E)t.set(s,0);return e.forEach(s=>{const o=t.get(s.apparatus)||0;t.set(s.apparatus,o+1)}),t}async getInspectionLogs(e=7){try{const t=new Date;t.setDate(t.getDate()-e);const s=await fetch(`${h}/issues?state=all&labels=${f.LOG}&per_page=100&since=${t.toISOString()}`,{method:"GET",headers:this.getHeaders(!0)});if(!s.ok)throw s.status===401?new Error("Unauthorized. Please enter the admin password."):new Error(`Failed to fetch logs: ${s.statusText}`);const o=await s.json();return Array.isArray(o)?o:[]}catch(t){throw console.error("Error fetching inspection logs:",t),t}}async getDailySubmissions(){try{const e=await this.getInspectionLogs(1),t=await this.getInspectionLogs(30),s=new Date().toLocaleDateString("en-US"),o=[],c=new Map,l=new Map;return E.forEach(r=>{c.set(r,0)}),t.forEach(r=>{const n=r.title.match(/\[(.+)\]\s+Daily Inspection/);if(n){const a=n[1],i=c.get(a)||0;c.set(a,i+1);const d=new Date(r.created_at).toLocaleDateString("en-US"),u=l.get(a);(!u||new Date(r.created_at)>new Date(u))&&l.set(a,d)}}),e.forEach(r=>{const n=r.title.match(/\[(.+)\]\s+Daily Inspection/);if(n){const a=n[1];new Date(r.created_at).toLocaleDateString("en-US")===s&&!o.includes(a)&&o.push(a)}}),{today:o,totals:c,lastSubmission:l}}catch(e){throw console.error("Error getting daily submissions:",e),e}}async analyzeLowStockItems(){try{const e=new Date;e.setDate(e.getDate()-30);const t=await fetch(`${h}/issues?state=all&labels=${f.DEFECT}&per_page=100&since=${e.toISOString()}`,{method:"GET",headers:this.getHeaders(!0)});if(!t.ok)throw new Error(`Failed to fetch defects for analysis: ${t.statusText}`);const s=await t.json(),o=Array.isArray(s)?s:[],c=new Map;return o.forEach(r=>{if(r.title.includes("Missing")){const n=r.title.match($);if(n){const[,a,i,d]=n,u=`${i}:${d}`;if(c.has(u)){const m=c.get(u);m.apparatus.add(a),m.occurrences++}else c.set(u,{compartment:i,apparatus:new Set([a]),occurrences:1})}}}),Array.from(c.entries()).filter(([,r])=>r.occurrences>=2).map(([r,n])=>({item:r.split(":")[1],compartment:n.compartment,apparatus:Array.from(n.apparatus),occurrences:n.occurrences})).sort((r,n)=>n.occurrences-r.occurrences)}catch(e){throw console.error("Error analyzing low stock items:",e),e}}async sendNotification(e){try{const t=await fetch(`${h}/notify`,{method:"POST",headers:this.getHeaders(),body:JSON.stringify(e)});if(!t.ok)throw new Error("Failed to send notification");return t.json()}catch(t){throw console.error("Error sending notification:",t),t}}async getEmailConfig(e){try{const t=await fetch(`${h}/config/email`,{method:"GET",headers:{"X-Admin-Password":e}});if(!t.ok)throw t.status===401?new Error("Unauthorized"):new Error("Failed to fetch email configuration");return t.json()}catch(t){throw console.error("Error fetching email config:",t),t}}async updateEmailConfig(e,t){try{const s=await fetch(`${h}/config/email`,{method:"PUT",headers:{"Content-Type":"application/json","X-Admin-Password":e},body:JSON.stringify(t)});if(!s.ok)throw s.status===401?new Error("Unauthorized"):new Error("Failed to update email configuration");return s.json()}catch(s){throw console.error("Error updating email config:",s),s}}async sendManualDigest(e){const t=await fetch(`${h}/digest/send`,{method:"POST",headers:{"X-Admin-Password":e}});if(!t.ok)throw t.status===401?new Error("Unauthorized"):new Error("Failed to send digest");return t.json()}async getAIInsights(e,t="week",s){const o=new URLSearchParams({timeframe:t,...s&&{apparatus:s}}),c=await fetch(`${h}/analyze?${o}`,{method:"GET",headers:{"X-Admin-Password":e}});if(!c.ok)throw c.status===401?new Error("Unauthorized"):c.status===503?new Error("AI features not enabled"):new Error("Failed to fetch AI insights");return c.json()}async createSupplyTasksForDefects(e,t){try{const s=await fetch(`${h}/tasks/create`,{method:"POST",headers:this.getHeaders(),body:JSON.stringify({defects:e,apparatus:t})});if(!s.ok)throw new Error(`Failed to create supply tasks: ${s.statusText}`);const o=await s.json();console.log(`Created ${o.tasksCreated||0} supply tasks from ${e.length} defects`)}catch(s){throw console.error("Error creating supply tasks:",s),s}}}const z=new I;export{N as C,H as M,U as T,O as U,_ as X,j as a,R as b,z as g};
//# sourceMappingURL=github-DwNqGJYJ.js.map
