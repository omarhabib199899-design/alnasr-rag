import {
  ChangeDetectionStrategy,
  Component
} from '@angular/core';

import { DevelopedContentComponent } from '../components/developed-content/developed-content.component';
import { DevelopedCompanyComponent } from '../components/developed-company/developed-company.component';

/**
 * Section component responsible for displaying developed content and company details.
 */
@Component({
  selector: 'app-developed',
  standalone: true,
  imports: [DevelopedContentComponent, DevelopedCompanyComponent],
  templateUrl: './developed-section.component.html',
  styleUrl: './developed-section.component.scss',
  changeDetection: ChangeDetectionStrategy.OnPush
})
export class DevelopedSectionComponent {}