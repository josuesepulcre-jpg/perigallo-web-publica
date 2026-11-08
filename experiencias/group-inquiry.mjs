export const GROUP_WHATSAPP_NUMBER = '34691499985';
export function validateGroupInquiry(input, menus) {
  const value = Object.fromEntries(['first_name','last_name','date','shift','people','menu','phone','email'].map(key=>[key,String(input[key]??'').trim()]));
  for (const key of ['first_name','last_name']) if (!value[key] || value[key].length>160 || /[\r\n]/.test(value[key])) throw new Error('Indica tu nombre y apellidos.');
  if(!/^2026-12-(0[1-9]|[12][0-9]|3[01])$/.test(value.date)) throw new Error('Elige un día de diciembre de 2026.');
  if(!['lunch','dinner'].includes(value.shift)) throw new Error('Indica si os interesa comida o cena.');
  if(!/^\d+$/.test(value.people)||Number(value.people)<1||Number(value.people)>100) throw new Error('Indica un número de personas entre 1 y 100.');
  if(!Object.hasOwn(menus,value.menu)) throw new Error('Selecciona uno de los cuatro menús.');
  if(!/^[+\d\s().-]+$/.test(value.phone)||value.phone.replace(/\D/g,'').length<9||value.phone.replace(/\D/g,'').length>15) throw new Error('Introduce un teléfono de contacto válido.');
  if(value.email.length>190||!/^\S+@[^\s@]+\.[^\s@]+$/.test(value.email)) throw new Error('Introduce un correo electrónico válido.');
  if(input.privacy!==true) throw new Error('Acepta el contacto para gestionar esta consulta.');
  return {...value,people:Number(value.people),privacy:true};
}
export function buildGroupMessage(input, menus) {
  const data=validateGroupInquiry(input,menus),menu=menus[data.menu];
  const date=new Intl.DateTimeFormat('es-ES',{weekday:'long',day:'numeric',month:'long',year:'numeric',timeZone:'UTC'}).format(new Date(data.date+'T12:00:00Z'));
  return ['Hola, me gustaría consultar disponibilidad para una mesa en diciembre.','',
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
