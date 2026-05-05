const password = document.getElementById("password");
const icon = document.getElementById("togglePassword");

icon.addEventListener("click", function () {

  const isHidden = password.type === "password";

  password.type = isHidden ? "text" : "password";

  this.classList.toggle("bi-eye");
  this.classList.toggle("bi-eye-slash");
});