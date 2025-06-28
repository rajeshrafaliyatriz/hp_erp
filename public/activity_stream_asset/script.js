var buttons = document.querySelectorAll("[id^='dropdownMenuButton']");
buttons.forEach(function (button) {
  button.addEventListener("click", function () {
    button.classList.toggle("active");
    var dropdownMenu = this.nextElementSibling;
    dropdownMenu.classList.toggle("d-none");
    dropdownMenu.classList.toggle("d-block");
  });
});
