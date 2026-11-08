export const GROUP_WHATSAPP_NUMBER = '34691499985';
export function validateGroupInquiry(input, menus, options={}) {
  const value = Object.fromEntries(['first_name','last_name','date','shift','people','menu','phone','email'].map(key=>[key,String(input[key]??'').trim()]));
  for (const key of ['first_name','last_name']) if (!value[key] || value[key].length>160 || /[\r\n]/.test(value[key])) throw new Error('Indica tu nombre y apellidos.');
  if(!/^2026-12-(0[1-9]|[12][0-9]|3[01])$/.test(value.date)) throw new Error('Elige un día de diciembre de 2026.');
  if(options.kind==='tasting') {
    const day=new Date(value.date+'T12:00:00Z').getUTCDay();
    if(value.date==='2026-12-25'||![0,5,6].includes(day)) throw new Error('El menú degustación está disponible los viernes, sábados y domingos de diciembre, excepto el 25.');
    if(value.shift!=='lunch') throw new Error('El menú degustación solo está disponible para comida de mediodía.');
  }
  if(!['lunch','dinner'].includes(value.shift)) throw new Error('Indica si os interesa comida o cena.');
  if(!/^\d+$/.test(value.people)||Number(value.people)<1||Number(value.people)>(options.maxPeople??100)) throw new Error('Indica un número de personas entre 1 y '+(options.maxPeople??100)+'.');
  if(!Object.hasOwn(menus,value.menu)) throw new Error('Selecciona el menú que os interesa.');
  if(!/^[+\d\s().-]+$/.test(value.phone)||value.phone.replace(/\D/g,'').length<9||value.phone.replace(/\D/g,'').length>15) throw new Error('Introduce un teléfono de contacto válido.');
  if(value.email.length>190||!/^\S+@[^\s@]+\.[^\s@]+$/.test(value.email)) throw new Error('Introduce un correo electrónico válido.');
  if(input.privacy!==true) throw new Error('Acepta el contacto para gestionar esta consulta.');
  return {...value,people:Number(value.people),privacy:true};
}
export function buildGroupMessage(input, menus, options={}) {
  const data=validateGroupInquiry(input,menus,options),menu=menus[data.menu];
  const date=new Intl.DateTimeFormat('es-ES',{weekday:'long',day:'numeric',month:'long',year:'numeric',timeZone:'UTC'}).format(new Date(data.date+'T12:00:00Z'));
  return [options.kind==='tasting'?'Hola, me gustaría consultar disponibilidad para el menú degustación en diciembre.':'Hola, me gustaría consultar disponibilidad para los menús de grupo en diciembre.','',
    'Nombre y apellidos: '+data.first_name+' '+data.last_name,
    'Fecha: '+date,
    'Turno: '+(data.shift==='lunch'?'Comida (mediodía)':'Cena (noche)'),
    'Personas: '+data.people,
    'Menú: '+menu.title+' · '+menu.price+' por persona',
    'Teléfono: '+data.phone,
    'Correo: '+data.email,'',
    'Quedo pendiente de vuestra confirmación de disponibilidad.'].join('\n');
}
export function groupWhatsAppUrl(message) {
  return 'https://wa.me/'+GROUP_WHATSAPP_NUMBER+'?text='+encodeURIComponent(message);
}
