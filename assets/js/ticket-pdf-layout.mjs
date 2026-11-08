/* Shared ticket composition for email attachments and browser downloads. */
export const TICKET_PAGE = [148, 210];
export function drawTicketPdf(pdf, order, ticket, index, total, assets) {
  const ink = [20,20,20], paper = [243,243,238], green = [60,81,66];
  const secondary = [102,109,98], rule = [202,205,196];
  const font = assets.fontFamily || 'helvetica';
  const clean = value => String(value ?? '').replace(/[\u0000-\u001f\u007f]/g,' ').trim();
  const text = (value,x,y,size=9,color=ink,opts={}) => {
    pdf.setFont(font,'normal'); pdf.setFontSize(size); pdf.setTextColor(...color);
    pdf.setCharSpace(0); pdf.text(clean(value),x,y,opts);
  };
  const label = (value,x,y,color=secondary,align='left') => {
    pdf.setFont(font,'normal'); pdf.setFontSize(6); pdf.setTextColor(...color); pdf.setCharSpace(.55);
    pdf.text(clean(value).toLocaleUpperCase('es'),x,y,{align}); pdf.setCharSpace(0);
  };
  const fit = (value,width,maxLines,initial=11,min=7) => {
    pdf.setFont(font,'normal');
    let size=initial, lines;
    do { pdf.setFontSize(size); lines=pdf.splitTextToSize(clean(value),width); if(lines.length<=maxLines)break; size-=.5; } while(size>=min);
    if(lines.length>maxLines) {
      lines=lines.slice(0,maxLines); let last=lines[maxLines-1];
      while(pdf.getTextWidth(last+'…')>width && last.length)last=last.slice(0,-1);
      lines[maxLines-1]=last+'…';
    }
    return {size:Math.max(size,min),lines};
  };
  const block = (value,x,y,width,maxLines=2,size=10,min=8,color=ink,lineHeight=4.5) => {
    const f=fit(value,width,maxLines,size,min);
    f.lines.forEach((line,i)=>text(line,x,y+i*lineHeight,f.size,color));
  };
  const date = ticketDateParts(ticket.starts_at);
  const title=clean(ticket.event_title)||'Una experiencia Perigallo';
  const venue=[clean(ticket.location),clean(ticket.locality)].filter(Boolean).join(' · ')||'Ubicación por confirmar';
  const name=clean(ticket.attendee_name)||clean(order.name)||'Por confirmar';
  const kind=clean(ticket.ticket_type_name)||'Entrada general';
  const number=String(index).padStart(2,'0');

  // A compact, print-friendly ticket with an editorial front and an access stub.
  pdf.setFillColor(...paper); pdf.rect(0,0,148,210,'F');
  pdf.setFillColor(...ink); pdf.rect(0,0,148,28,'F');
  if(assets.logo) pdf.addImage(assets.logo,'PNG',12,10,51,51*150/1240);
  else {
    pdf.setFillColor(...paper); [12,14.7,17.4].forEach(x=>pdf.rect(x,9.8,2.3,7,'F'));
    text('PERIGALLO',23,15.2,14,paper);
  }
  label('Entrada oficial',136,11.5,paper,'right');
  text(number+' / '+String(total).padStart(2,'0'),136,19,11,paper,{align:'right'});

  label('El gusto de celebrar',12,39);
  const titleParts=title.split(/\s+[—–]\s+/);
  const hasEdition=titleParts.length===2 && titleParts[0].length<45;
  if(hasEdition) {
    block(titleParts[0],12,52,124,1,14,11,secondary);
    const headline=fit(titleParts[1],124,2,37,24);
    headline.lines.forEach((line,i)=>text(line,12,69+i*12,headline.size));
  } else {
    const heading=fit(title,124,3,29,19);
    heading.lines.forEach((line,i)=>text(line,12,54+i*10,heading.size));
  }
  const subtitle=clean(ticket.event_subtitle);
  if(subtitle)block(subtitle,12,hasEdition?88:84,124,2,9,8,secondary,4.3);
  pdf.setDrawColor(...rule); pdf.setLineWidth(.22); pdf.line(12,96,136,96);

  label(date.weekday||'Fecha',12,104);
  text(date.day||'—',12,121,30);
  label(date.month||'Por confirmar',34,113);
  text(date.year||'',34,121,10,secondary);
  label('Hora',136,104,secondary,'right');
  const end=ticketDateParts(ticket.ends_at);
  const time=date.time?(date.time+(end.time?' – '+end.time:'')):'Por confirmar';
  const timeFit=fit(time,59,1,end.time?16:20,10);
  text(time,136,120,timeFit.size,ink,{align:'right'});
  pdf.setDrawColor(...rule); pdf.line(12,130,136,130);

  label('Asistente',12,138);
  block(name,12,145,78,2,11,8,ink,4.6);
  label('Tu entrada',100,138);
  block(kind,100,145,36,2,9.5,7.5,ink,4.2);
  block(venue,12,157,124,1,8,6.5,secondary);
  const dress=clean(ticket.dress_code);
  if(dress)block('Vestimenta · '+(/total\s+white|blanco/i.test(dress)?'Total White · Obligatorio ir de blanco':dress),12,162,124,1,7,5.5,secondary);

  // High-contrast QR and a clear quiet zone; encoded access data is untouched.
  pdf.setFillColor(...green); pdf.rect(0,166,148,44,'F');
  pdf.setDrawColor(...paper); pdf.setLineWidth(.18); pdf.setLineDashPattern([1,1.4],0); pdf.line(4,166,144,166); pdf.setLineDashPattern([],0);
  pdf.setFillColor(...paper); pdf.circle(0,166,2.7,'F'); pdf.circle(148,166,2.7,'F');
  pdf.setFillColor(255,255,255); pdf.rect(12,171,34,34,'F');
  assets.drawQr(pdf,16,175,26);
  label('Tu acceso a la experiencia',53,176,paper);
  text('Nos vemos dentro.',53,185,14,paper);
  text('Presenta este QR al llegar.',53,191,7.2,paper);
  const code=clean(ticket.public_code)||'—';
  let codeSize=7.8; pdf.setFont('courier','normal'); pdf.setFontSize(codeSize);
  while(pdf.getTextWidth(code)>83 && codeSize>4){codeSize-=.25;pdf.setFontSize(codeSize);}
  pdf.setTextColor(...paper); pdf.text(code,53,198);
  text(order.is_test?'MUESTRA · SIN VALIDEZ':'perigallo.com',53,204,6.3,paper);
  text('Ref. '+clean(order.reference||'—'),136,204,6.3,paper,{align:'right'});
}

export function ticketDateParts(value) {
  const raw=String(value||'').trim();
  let m=raw.match(/^(\d{4})-(\d\d)-(\d\d)(?:[T ](\d\d):(\d\d))?/);
  if(!m) { const f=raw.match(/^(\d\d)\/(\d\d)\/(\d{4})(?:\s*·?\s*(\d\d):(\d\d))?/); if(f)m=[f[0],f[3],f[2],f[1],f[4],f[5]]; }
  if(!m)return {};
  const date=new Date(Date.UTC(+m[1],+m[2]-1,+m[3]));
  if(date.getUTCFullYear()!==+m[1]||date.getUTCMonth()!==+m[2]-1||date.getUTCDate()!==+m[3])return {};
  return {day:String(+m[3]).padStart(2,'0'),month:new Intl.DateTimeFormat('es-ES',{month:'long',timeZone:'UTC'}).format(date),year:m[1],weekday:new Intl.DateTimeFormat('es-ES',{weekday:'long',timeZone:'UTC'}).format(date),time:m[4]?m[4]+':'+m[5]:''};
}
