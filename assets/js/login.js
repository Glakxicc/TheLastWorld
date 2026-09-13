// --- Import ---

require('dotenv').config();
// --- Variable ---
// -- Input --

const inputDiscord = document.querySelector("#discord");
const inputAgeIrl = document.querySelector("#age_irl");
const inputName = document.querySelector("#first_name");
const inputLastName = document.querySelector("#last_name");
const inputAgeCharacter = document.querySelector("#age_character");
const inputBorn = document.querySelector("#rp_born");
const inputXp = document.querySelector("#rp_experience");
const inputStory = document.querySelector("#rp_story");

// -- Button --

const staffBtn = document.querySelector("#staff");
const illegalBtn = document.querySelector("#illegal");
const sendFormfBtn = document.querySelector("#send-form");
const loginBtn = document.querySelector("#login");

// -- State --

let staffState = true;
let illegalState = false;

// --- EventListener ---
// -- Staff Button --

staffBtn.addEventListener("click", (event) => {
  switch (staffState) {
    case true:
      staffState = false;
      Toastify({
        text: "Option désactivé.",
        duration: 1300,
        style: {
          background: "#ff4242",
        },
      }).showToast();
      event.target.style.backgroundColor = "#ff4242";
      break;

    case false:
      staffState = true;
      Toastify({
        text: "Option activé.",
        duration: 1300,
        style: {
          background: "#25d940",
        },
      }).showToast();
      event.target.style.backgroundColor = "#25d940";
      break;
  }
});

staffBtn.addEventListener("mouseenter", (event) => {
  switch (staffState) {
    case true:
      event.target.style.backgroundColor = "#25d940c6";
      break;

    case false:
      event.target.style.backgroundColor = "#ff4242dc";
      break;
  }
});

staffBtn.addEventListener("mouseout", (event) => {
  switch (staffState) {
    case true:
      event.target.style.backgroundColor = "#25d940";
      break;
    case false:
      event.target.style.backgroundColor = "#ff4242";

      break;
  }
});

// -- Illegal Button --

illegalBtn.addEventListener("click", (event) => {
  switch (illegalState) {
    case false:
      illegalState = true;
      event.target.style.backgroundColor = "#25d940";
      Toastify({
        text: "Option activé.",
        duration: 1300,
        style: {
          background: "#25d940",
        },
      }).showToast();
      break;

    case true:
      illegalState = false;
      event.target.style.backgroundColor = "#ff4242";
      Toastify({
        text: "Option désactivé.",
        duration: 1300,
        style: {
          background: "#ff4242",
        },
      }).showToast();
      break;
  }
});

illegalBtn.addEventListener("mouseenter", (event) => {
  switch (illegalState) {
    case true:
      event.target.style.backgroundColor = "#25d940c6";
      break;

    case false:
      event.target.style.backgroundColor = "#ff4242dc";
      break;
  }
});

illegalBtn.addEventListener("mouseout", (event) => {
  switch (illegalState) {
    case true:
      event.target.style.backgroundColor = "#25d940";
      break;

    case false:
      event.target.style.backgroundColor = "#ff4242";
      break;
  }
});

sendFormfBtn.addEventListener("click", (event) => {
  event.target.style.backgroundColor = "#25d940";
  setTimeout(() => {
    event.target.style.backgroundColor = "";
  }, 500);
  //   Toastify({
  //   text: "Un ticket à été créer sur le discord.",
  //   duration: 1300,
  //   style: {
  //     background: "blue",
  //   },
  // }).showToast();

  sendInformation();
});

loginBtn.addEventListener("click", (event) => {
  alert("Fonctionnalité non ajouté pour l'instant.");
});

async function sendInformation() {
  const discordValue = inputDiscord.value;
  const ageIRLValue = inputAgeIrl.value;
  const nameValue = inputName.value;
  const lastNameValue = inputLastName.value;
  const ageRPValue = inputAgeCharacter.value;
  const bornValue = inputBorn.value;
  const xpValue = inputXp.value;
  const storyValue = inputStory.value;

  inputDiscord.value = "";
  inputAgeIrl.value = "";
  inputName.value = "";
  inputLastName.value = "";
  inputAgeCharacter.value = "";
  inputBorn.value = "";
  inputXp.value = "";
  inputStory.value = "";

  const payload = {
    embeds: [
      {
        title: "Formulaire TLW",
        description: "Formulaire du site",
        color: 0xff4242,
        fields: [
          {
            name: "Discord",
            value: discordValue,
            inline: true,
          },
          {
            name: "Age IRL",
            value: ageIRLValue,
            inline: true,
          },
          {
            name: "Prénom Personnage",
            value: nameValue,
            inline: true,
          },
          {
            name: "Nom Personnage",
            value: lastNameValue,
            inline: true,
          },
          {
            name: "Age RP",
            value: ageRPValue,
            inline: true,
          },
          {
            name: "Née à",
            value: bornValue,
            inline: true,
          },
          {
            name: "Expérience RP",
            value: xpValue,
            inline: true,
          },
          {
            name: "Histoire du Personnage",
            value: storyValue,
            inline: true,
          },
          {
            name: "Rp Rebelle ?",
            value: illegalState,
            inline: true,
          },
          {
            name: "Demande à être staff ?",
            value: staffState,
            inline: true,
          },
        ],
      },
    ],
  };

  if (
    discordValue == "" ||
    ageIRLValue == "" ||
    nameValue == "" ||
    lastNameValue == "" ||
    ageRPValue == "" ||
    bornValue == "" ||
    xpValue == "" ||
    storyValue == ""
  ) {
    Toastify({
      text: "Vous devez remplir tout le formulaire",
      duration: 1300,
      style: {
        background: "#ff4242",
      },
    }).showToast();
  } else {
    const webhookurl = process.env.DISCORD_WEBHOOK_URL;
    const response = await fetch(webhookurl, {
      method: "POST",
      headers: {
        "Content-Type": "application/json",
      },
      body: JSON.stringify(payload),
    });

    if (!response.ok) {
      console.error(
        "Erreur lors de l'envoi vers Discord:",
        response.statusText,
      );
    }

    Toastify({
      text: "Information envoyé aux staff sur le discord.",
      duration: 1300,
      style: {
        background: "blue",
      },
    }).showToast();
  }
}
