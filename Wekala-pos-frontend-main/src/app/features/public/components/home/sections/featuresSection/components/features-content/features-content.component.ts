import {
  ChangeDetectionStrategy,
  Component,
  signal
} from '@angular/core';

/**
 * Component displaying the overview header for the features section.
 */
@Component({
  selector: 'app-features-content',
  standalone: true,
  imports: [],
  templateUrl: './features-content.component.html',
  styleUrl: './features-content.component.scss',
  changeDetection: ChangeDetectionStrategy.OnPush
})
export class FeaturesContentComponent {

  // Section badge label
  readonly badge = signal('Features');

  // Main heading for the features section
  readonly title = signal(
    'Everything You Need to Run Your Business'
  );

  // Descriptive summary of the value proposition
  readonly description = signal(
    'A complete toolkit built into a single platform — no juggling multiple systems.'
  );

}