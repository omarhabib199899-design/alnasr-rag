import {
  ChangeDetectionStrategy,
  Component,
  ElementRef,
  afterNextRender,
  computed,
  input,
  signal,
  viewChild
} from '@angular/core';

@Component({
  selector: 'app-count-up',
  standalone: true,
  templateUrl: './count-up.component.html',
  styleUrl: './count-up.component.scss',
  changeDetection: ChangeDetectionStrategy.OnPush
})
export class CountUpComponent {

  /* ==========================================================
     INPUTS
  ========================================================== */

  // The target numeric value to count up to
  readonly value = input.required<number>();

  // Duration of the animation in milliseconds
  readonly duration = input(1400);

  // Optional string to display before the number
  readonly prefix = input('');

  // Optional string to display after the number
  readonly suffix = input('');

  // Number of decimal places to show
  readonly decimals = input(0);

  // Toggle for number grouping separators (e.g., thousands separators)
  readonly separator = input(true);

  /* ==========================================================
     STATE
  ========================================================== */

  // Tracks the current animated value
  readonly current = signal(0);

  // Tracks whether the animation has already started
  readonly started = signal(false);

  // Reference to the DOM element for intersection observation
  readonly element =
    viewChild.required<ElementRef<HTMLElement>>('counter');

  /* ==========================================================
     DISPLAY
  ========================================================== */

  // Formats the current value into a display-ready string with prefixes/suffixes
  readonly display = computed(() => {

    let value = this.current();

    let text = value.toFixed(this.decimals());

    if (this.separator()) {

      text = Number(text).toLocaleString();

    }

    return `${this.prefix()}${text}${this.suffix()}`;

  });

  /* ==========================================================
     INIT
  ========================================================== */

  constructor() {

    // Initialize IntersectionObserver after component renders to trigger animation when in view
    afterNextRender(() => {

      const observer = new IntersectionObserver(

        ([entry]) => {

          if (!entry.isIntersecting || this.started()) return;

          this.started.set(true);

          this.startAnimation();

          observer.disconnect();

        },

        {
          threshold: .35
        }

      );

      observer.observe(this.element().nativeElement);

    });

  }

  /* ==========================================================
     ANIMATION
  ========================================================== */

  // Executes the count-up animation logic using requestAnimationFrame
  private startAnimation() {

    const target = this.value();

    const duration = this.duration();

    const start = performance.now();

    const animate = (now: number) => {

      const progress = Math.min(
        (now - start) / duration,
        1
      );

      // Cubic ease-out timing function
      const eased =
        1 - Math.pow(1 - progress, 3);

      this.current.set(target * eased);

      if (progress < 1) {

        requestAnimationFrame(animate);

      } else {

        // Ensure final target value is set accurately
        this.current.set(target);

      }

    };

    requestAnimationFrame(animate);

  }

}