function l(c){const{user:n,apparatus:a,date:o,items:t,defects:r}=c,i=new Map;r.forEach(e=>{i.set(e.item,e.compartment)});const p=t.map(e=>({compartment:i.get(e.name)||void 0,itemName:e.name,status:e.status||"present",notes:e.notes})),u=p.filter(e=>e.status!=="present").length;return{inspector:`${n.name} (${n.rank})`,apparatus:a,date:o,items:p,summary:{totalItems:t.length,issuesFound:u}}}async function $(c,n,a){const o={"Content-Type":"application/json"};a&&(o["X-Admin-Password"]=a);const t=await fetch(`${c}/receipts`,{method:"POST",headers:o,body:JSON.stringify(n)});if(!t.ok){const r=await t.json().catch(()=>({message:"Unknown error"}));throw new Error(`Failed to create receipt: ${t.status} ${r.message||t.statusText}`)}return await t.json()}function f(c){const{inspector:n,apparatus:a,date:o,items:t,summary:r}=c,i=new Date(o).toLocaleString("en-US",{dateStyle:"full",timeStyle:"short"}),p=r?.totalItems??t.length,u=r?.issuesFound??t.filter(s=>s.status!=="present").length,e=t.map(s=>{const m=s.status==="present"?"✅":s.status==="missing"?"❌":"⚠️",d=s.status==="present"?"Present":s.status==="missing"?"Missing":"Damaged";return`- **${s.compartment||"N/A"}**: ${s.itemName} - ${m} ${d}${s.notes?` (_${s.notes}_)`:""}`}).join(`
`);return`
## 🚒 Inspection Receipt

**Apparatus:** ${a}  
**Inspector:** ${n}  
**Date:** ${i}

### 📊 Summary
- **Items Checked:** ${p}
- **Issues Found:** ${u}

### Inspection Details
${e}

---
_This receipt was embedded as a fallback. Hosted receipt creation was unavailable._
`.trim()}export{f as buildReceiptMarkdown,l as buildReceiptPayloadFromInspection,$ as createHostedReceipt};
//# sourceMappingURL=receipt-nVCul5S8.js.map
