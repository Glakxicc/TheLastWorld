export function error415() {
      const page = `
  <body>
    <main>
      <h1>Erreur 415 !</h1>
      <p>Ce site est conçu pour être visiter sur un ordinateur</p>

      <style>
        @import url("https://fonts.googleapis.com/css2?family=Black+Ops+One&display=swap");
        * {
          margin: 0;
          padding: 0;
        }
        html {
          background-color: #303030;
          color: #fff;
          font-family: "Black Ops One", system-ui;
          font-style: normal;
          font-size: large;
        }
          
        main {
          display: flex;
          flex-wrap: wrap;
          flex-direction: column;
          justify-content: center;
          text-align: center;
          align-items: center;
          height: 100dvh;
          
        }
      </style>
    </main>
  </body>
  `;
    return (document.querySelector("body").innerHTML = page);
}
