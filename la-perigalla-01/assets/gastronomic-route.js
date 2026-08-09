const gastronomicRoute = [
  { scene: 'La isla nos recibe', dish: 'Licor de palo' },
  { scene: 'Mercat de la Vila', dish: 'Pan payés, tomate, quesos de Mahón, jamón y sobrasada con miel' },
  { scene: 'Todo empezó con un mensaje', dish: 'Orelletes de la sort' },
  { scene: 'Paseo por la playa', dish: 'Tartar de vieira' },
  { scene: 'Las Dalias', dish: 'Coca ibicenca hojaldrada' },
  { scene: 'Marina Botafoch', dish: 'Gilda marina' },
  { scene: 'Snorkel', dish: 'Erizo de mar gratinado' },
  { scene: 'Cala d’Hort', dish: 'Ostras en vivo y ceviche de corvina' },
  { scene: 'Puesta de sol', dish: 'Panipuri de tartar de atún y gel de champán' },
  { scene: 'Beso Beach', dish: 'Trampantojo de paté de mejillón' },
  { scene: 'Casa payesa', dish: 'Croqueta de sofrit pagès' },
  { scene: 'Tagomago', dish: 'Éclair de gamba roja' },
  { scene: 'Santa Gertrudis', dish: 'Ensalada payesa' },
  { scene: 'Feria hippie', dish: 'Puesto hippie de chupachups de ñora rellenos, palomitas del Mediterráneo, chips, aceitunas y piruletas de mazorca' },
  { scene: 'Formentera', dish: 'Tartaleta de patata, sobrasada, huevo de codorniz y langosta' },
  { scene: 'Es Vedrà', dish: 'Bullit de peix reinterpretado' },
  { scene: 'La pedida', dish: 'Hojaldre, crema agria de queso y caviar' },
  { scene: 'Dalt Vila', dish: 'Frita de polp' },
  { scene: 'Sunset Es Vedrà', dish: 'Brochetas a la brasa: calamar, pollo payés y porcella ibérica' },
];

function renderGastronomicRoute() {
  const route = document.querySelector('.gastronomic-route');

  if (!route || route.querySelectorAll('.gastronomic-route__dish').length === gastronomicRoute.length) {
    return;
  }

  route.replaceChildren(
    ...gastronomicRoute.map(({ scene, dish }, index) => {
      const item = document.createElement('li');
      const number = document.createElement('span');
      const copy = document.createElement('div');
      const sceneTitle = document.createElement('p');
      const dishTitle = document.createElement('p');

      number.className = 'gastronomic-route__number';
      number.textContent = String(index + 1).padStart(2, '0');
      sceneTitle.className = 'gastronomic-route__scene';
      sceneTitle.textContent = scene;
      dishTitle.className = 'gastronomic-route__dish';
      dishTitle.textContent = dish;
      copy.append(sceneTitle, dishTitle);
      item.append(number, copy);
      return item;
    }),
  );
}

const routeObserver = new MutationObserver(renderGastronomicRoute);
routeObserver.observe(document.documentElement, { childList: true, subtree: true });
renderGastronomicRoute();
