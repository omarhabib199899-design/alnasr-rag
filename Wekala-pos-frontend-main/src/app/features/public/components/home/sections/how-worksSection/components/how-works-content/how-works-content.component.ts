import {
  ChangeDetectionStrategy,
  Component,
  signal
} from '@angular/core';

/**
 * Component representing the header and introductory text for the "How It Works" section.
 */
@Component({
  selector: 'app-how-works-content',
  standalone: true,
  imports: [],
  templateUrl: './how-works-content.component.html',
  styleUrl: './how-works-content.component.scss',
  changeDetection: ChangeDetectionStrategy.OnPush
})
export class HowWorksContentComponent {

  // Section label/badge
  readonly badge = signal('How It Works');

  // Main heading for the section
  readonly title = signal(
    'Start Managing Your Business in\nSimple Steps'
  );

}