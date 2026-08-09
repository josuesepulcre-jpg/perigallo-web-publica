(function () {
  "use strict";

  // Transcripción de los datos ya publicados por el recorrido gastronómico.
  // El prototipo los consume para no inventar una segunda propuesta editorial.
  window.Perigalla01Gastronomy = [
    ["La isla nos recibe", "Licor de palo", ["No contiene alérgenos"], "scene-01-licor-de-palo-cutout.png", "Cóctel de licor de palo con hielo y piel de naranja"],
    ["Mercat de la Vila", "Pan payés, tomate, quesos de Mahón, jamón y sobrasada con miel", ["Gluten", "Leche"], "scene-02-mercat-de-la-vila.png", "Puesto gastronómico del Mercat de la Vila con pan, queso y jamón"],
    ["Todo empezó con un mensaje", "Orelletes de la sort", ["Gluten", "Huevo"], "scene-03-orelleta-de-la-sort-cutout.png", "Orelleta salada con mensaje"],
    ["Paseo por la playa", "Tartar de vieira", ["Moluscos", "Pescado", "Frutos de cáscara", "Gluten"], "scene-04-paseo-por-la-playa-cutout.png", "Tartar de vieira servido en una concha"],
    ["Las Dalias", "Coca ibicenca hojaldrada", ["Gluten", "Leche"], "scene-05-coca-ibicenca-cutout.png", "Coca ibicenca de pimientos asados y aceituna"],
    ["Marina Botafoch", "Gilda marina", ["Pescado"], "scene-06-gilda-de-gerret-cutout.png", "Gilda de gerret con aceitunas y guindilla"],
    ["Snorkel", "Erizo de mar gratinado", ["Pescado", "Leche", "Huevo", "Apio", "Crustáceos", "Moluscos"], "scene-07-erizo-de-mar-cutout.png", "Erizo de mar gratinado"],
    ["Cala d’Hort", "Ostras en vivo y ceviche de corvina", ["Moluscos", "Pescado"], "scene-08-cala-d-hort.png", "Estación de ostras y ceviche preparada en directo"],
    ["Puesta de sol", "Panipuri de tartar de atún y gel de champán", ["Gluten", "Pescado", "Soja"], "scene-09-puesta-de-sol-cutout.png", "Bocado de puesta de sol servido en cuenco cuadrado"],
    ["Beso Beach", "Trampantojo de paté de mejillón", ["Moluscos"], "scene-10-beso-beach-cutout.png", "Paté de mejillón en forma de labios sobre una patata chip"],
    ["Casa payesa", "Croqueta de sofrit pagès", ["Huevo", "Gluten", "Leche"], "scene-12-croqueta-sofrit-pages-cutout.png", "Croqueta de sofrit pagès"],
    ["Tagomago", "Éclair de gamba roja", ["Gluten", "Huevo", "Leche", "Crustáceos", "Pescado"], "scene-15-eclair-gamba-roja-cutout.png", "Éclair salado de gamba roja"],
    ["Santa Gertrudis", "Ensalada payesa", ["Sulfitos"], "scene-13-ensalada-payesa-cutout.png", "Ensalada payesa de verduras en cuenco de barro"],
    ["Feria hippie", "Puesto hippie de chupachups de ñora rellenos, palomitas del Mediterráneo, chips, aceitunas y piruletas de mazorca", ["Pescado"], "scene-14-feria-las-dalias.png", "Puesto de feria con aperitivos y elaboraciones para compartir"],
    ["Formentera", "Tartaleta de patata, sobrasada, huevo de codorniz y langosta", ["Huevo", "Crustáceos"], "scene-16-formentera-langosta-cutout.png", "Tartaleta de patata, huevo de codorniz y langosta"],
    ["Es Vedrà", "Bullit de peix reinterpretado", ["Pescado", "Crustáceos", "Moluscos", "Huevo"], "scene-17-bullit-de-peix-cutout.png", "Bullit de peix reinterpretado en cuenco de barro"],
    ["La pedida", "Hojaldre, crema agria de queso y caviar", ["Gluten", "Leche", "Pescado", "Huevo"], "scene-19-pedida-cutout.png", "Hojaldre con forma de anillo coronado con caviar"],
    ["Dalt Vila", "Frita de polp", ["Moluscos"], "scene-20-frita-de-polp-cutout.png", "Frita de polp servida en cuenco blanco"],
    ["Sunset Es Vedrà", "Brochetas a la brasa: calamar, pollo payés y porcella ibérica", ["Moluscos"], "scene-18-brasas.png", "Estación de brochetas cocinadas a la brasa"]
  ].map(function (entry) {
    return { scene: entry[0], dish: entry[1], allergens: entry[2], image: "/la-perigalla-01/media/storytelling/perigalla-01/gastronomy/" + entry[3], alt: entry[4] };
  });
})();
