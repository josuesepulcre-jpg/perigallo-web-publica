/* One composition for server attachments and browser downloads. */
export const TICKET_PAGE = [148, 210];
export function drawTicketPdf(pdf, order, ticket, index, total, assets) {
  const ink=[23,24,22], paper=[245,242,232], gold=[193,172,126], muted=[177,179,168], line=[77,75,65];
  const font=assets.fontFamily||'helvetica';
  const clean=v=>String(v??'').replace(/[\u0000-\u001f\u007f]/g,' ').trim();
  const text=(v,x,y,size=9,color=paper,opts={})=>{pdf.setFont(font,'normal');pdf.setFontSize(size);pdf.setTextColor(...color);pdf.setCharSpace(0);pdf.text(clean(v),x,y,opts);};
  const label=(v,x,y,color=gold,align='left',size=6)=>{pdf.setFont(font,'normal');pdf.setFontSize(size);pdf.setTextColor(...color);pdf.setCharSpace(.6);pdf.text(clean(v).toLocaleUpperCase('es'),x,y,{align});pdf.setCharSpace(0);};
  const fit=(v,width,maxLines,initial,min)=>{
    pdf.setFont(font,'normal');let size=initial,rows=[];
    for(;size>=min;size-=.5){pdf.setFontSize(size);rows=pdf.splitTextToSize(String(v??'').split('\n').map(clean).join('\n'),width);if(rows.length<=maxLines)break;}
    size=Math.max(size,min);pdf.setFontSize(size);
    if(rows.length>maxLines){rows=rows.slice(0,maxLines);let last=rows[maxLines-1];while(last.length&&pdf.getTextWidth(last+'…')>width)last=last.slice(0,-1);rows[maxLines-1]=last+'…';}
    return {size,rows};
  };
  const block=(v,x,y,width,maxLines=2,size=11,min=8,color=paper,leading=4.6)=>{const f=fit(v,width,maxLines,size,min);f.rows.forEach((row,i)=>text(row,x,y+i*leading,f.size,color));};
  const date=ticketDateParts(ticket.starts_at),end=ticketDateParts(ticket.ends_at);
  const originalTitle=clean(ticket.event_title)||'Una experiencia Perigallo';
  const isChristmas=/navidad/i.test(originalTitle),isNewYear=/año nuevo|primera mesa/i.test(originalTitle),isHora=/hora\s*(cero|0)|tardevieja|nochevieja/i.test(originalTitle);
  const title=isHora?'Tardevieja.\nHora Cero.':isChristmas?'El día de\nNavidad.':isNewYear?'La primera\nmesa del año.':originalTitle.replace(/\s+[—–]\s+/g,'. ');
  const edition=isHora?'Especial 31 de diciembre':isChristmas?'Navidad en Perigallo':isNewYear?'Año Nuevo en Perigallo':'El gusto de celebrar';
  const name=clean(ticket.attendee_name)||clean(order.name)||'Por confirmar';
  const venue=[clean(ticket.location),clean(ticket.locality)].filter(Boolean).join(' · ')||'Ubicación por confirmar';

  pdf.setFillColor(...ink);pdf.rect(0,0,148,210,'F');
  if(assets.logo)pdf.addImage(assets.logo,'PNG',14,14,51,51*150/1240);
  else {pdf.setFillColor(...paper);[14,17,20].forEach(x=>pdf.rect(x,13,2.4,7,'F'));text('PERIGALLO',27,19,14);}
  label(order.is_test?'Muestra local':'Entrada individual',134,14.5,gold,'right',5.4);
  text(String(index).padStart(2,'0')+' / '+String(total).padStart(2,'0'),134,22,9,paper,{align:'right'});
  pdf.setDrawColor(...line);pdf.setLineWidth(.22);pdf.line(14,30,134,30);
  label(edition,14,41);

  // A restrained line drawing echoes the campaign covers; it never covers text.
  pdf.setDrawColor(124,113,85);pdf.setLineWidth(.25);
  if(isChristmas)drawTree(pdf,110,48);
  else if(isHora)drawClock(pdf,121,64);
  else if(isNewYear)drawToast(pdf,111,48);
  else {pdf.circle(122,61,11,'S');pdf.line(122,46,122,76);}
  const headline=fit(title,91,3,33,21);
  headline.rows.forEach((row,i)=>text(row,14,58+i*12.5,headline.size));
  const subtitle=clean(ticket.event_subtitle);
  if(subtitle)block(subtitle,14,88,120,1,8,6.5,muted);
  block(venue,14,98,120,1,9,6.5,paper);
  pdf.setDrawColor(...line);pdf.line(14,106,134,106);

  label('A nombre de',14,116);
  block(name,14,124,120,2,13,9,paper,5.1);
  label('Tu experiencia',14,138);
  const kind=clean(ticket.ticket_type_name)||'Entrada general';
  block(kind,14,145,120,1,10.5,8,paper);
  const dress=clean(ticket.dress_code);
  if(dress)block('Vestimenta · '+(/total\s+white|blanco/i.test(dress)?'Total White · Obligatorio ir de blanco':dress),14,152,120,1,7.2,5.5,muted);

  // A generous white quiet zone keeps the access QR easy to read on a phone.
  pdf.setFillColor(...paper);pdf.rect(10,158,128,43,'F');
  text(date.day||'—',16,174,28,ink);
  label(date.month||'Fecha por confirmar',37,167,ink,'left',6.5);
  text(date.year||'',37,175,10,ink);
  const hours=date.time?date.time+(end.time?' - '+end.time:''):'Hora por confirmar';
  block(hours,16,187,70,1,15,10,ink);
  text('Presenta tu QR al llegar.',16,195,7,[91,95,86]);
  pdf.setFillColor(255,255,255);pdf.rect(94,160,38,38,'F');
  assets.drawQr(pdf,98,164,30);
  text(clean(ticket.public_code)||'—',113,199.5,5.6,ink,{align:'center'});
  label(order.is_test?'Prueba · Sin validez de acceso':'perigallo.com',14,207,gold,'left',5.4);
  text('Ref. '+clean(order.reference||'—'),134,207,6,muted,{align:'right'});
}
function drawTree(pdf,x,y){
  const lines=[[[10,0],[3,11],[7,11],[0,22],[6,22],[-3,34],[23,34],[14,22],[20,22],[13,11],[17,11],[10,0]],[[10,34],[10,40]],[[5,40],[15,40]]];
  lines.forEach(points=>points.slice(1).forEach((p,i)=>pdf.line(x+points[i][0],y+points[i][1],x+p[0],y+p[1])));
}
function drawClock(pdf,x,y){
  pdf.circle(x,y,14,'S');for(let i=0;i<12;i++){const a=i*Math.PI/6;pdf.line(x+Math.sin(a)*12.2,y-Math.cos(a)*12.2,x+Math.sin(a)*13.1,y-Math.cos(a)*13.1);}pdf.line(x,y,x,y-9);pdf.line(x,y,x+5.7,y+3.2);pdf.circle(x,y,.6,'S');
}
function drawToast(pdf,x,y){
  for(const dx of [0,12]){pdf.line(x+dx,y,x+dx+8,y);pdf.line(x+dx,y,x+dx+1,y+15);pdf.line(x+dx+8,y,x+dx+7,y+15);pdf.lines([[1,3],[2,1],[2,-1],[1,-3]],x+dx+1,y+15,[1,1],'S');pdf.line(x+dx+4,y+19,x+dx+4,y+34);pdf.line(x+dx,y+34,x+dx+8,y+34);pdf.line(x+dx+.5,y+8,x+dx+7.5,y+8);}pdf.line(x+9,y-3,x+9,y-7);pdf.line(x+5,y-4,x+3,y-7);pdf.line(x+13,y-4,x+15,y-7);
}
export function ticketDateParts(value){
  const raw=String(value||'').trim();let m=raw.match(/^(\d{4})-(\d\d)-(\d\d)(?:[T ](\d\d):(\d\d))?/);
  if(!m){const f=raw.match(/^(\d\d)\/(\d\d)\/(\d{4})(?:\s*·?\s*(\d\d):(\d\d))?/);if(f)m=[f[0],f[3],f[2],f[1],f[4],f[5]];}
  if(!m)return {};const date=new Date(Date.UTC(+m[1],+m[2]-1,+m[3]));if(date.getUTCFullYear()!==+m[1]||date.getUTCMonth()!==+m[2]-1||date.getUTCDate()!==+m[3])return {};
  return {day:String(+m[3]).padStart(2,'0'),month:new Intl.DateTimeFormat('es-ES',{month:'long',timeZone:'UTC'}).format(date),year:m[1],weekday:new Intl.DateTimeFormat('es-ES',{weekday:'long',timeZone:'UTC'}).format(date),time:m[4]?m[4]+':'+m[5]:''};
}
