let index = 0;

function moveSlide(direction) {
  const track = document.getElementById("carouselTrack");
  const cards = document.querySelectorAll(".carousel-card");

  const cardsPerView = 3;
  const totalCards = cards.length;

  // max starting index (so last view still shows 3 cards)
  const maxIndex = totalCards - cardsPerView;

  index += direction;

  if (index < 0) index = 0;
  if (index > maxIndex) index = maxIndex;

  const card = cards[0];
  const style = window.getComputedStyle(card);
  const cardWidth = card.offsetWidth + parseFloat(style.paddingLeft) + parseFloat(style.paddingRight);

  track.style.transform = `translateX(-${index * cardWidth}px)`;
}

function requireLogin(){
    alert("You must login or sign up first to book a tutor.");
    window.location.href = "php/login.php";
}